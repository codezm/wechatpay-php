#!/usr/bin/env php
<?php declare(strict_types=1);

// load autoload.php
$possibleFiles = [__DIR__.'/../vendor/autoload.php', __DIR__.'/../../../autoload.php', __DIR__.'/../../autoload.php'];
$file = null;
foreach ($possibleFiles as $possibleFile) {
    if (file_exists($possibleFile)) {
        $file = $possibleFile;
        break;
    }
}
if (null === $file) {
    throw new RuntimeException('Unable to locate autoload.php file.');
}

require_once $file;
unset($possibleFiles, $possibleFile, $file);

use GuzzleHttp\Middleware;
use GuzzleHttp\Utils;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
use WeChatPay\Builder;
use WeChatPay\ClientDecoratorInterface;
use WeChatPay\Crypto\AesGcm;

 /**
  * CertificateDownloader class
  */
class CertificateDownloader
{
    public function run(): void
    {
        $opts = $this->parseOpts();

        if (!$opts) {
            $this->printHelp();
            return;
        }

        if (isset($opts['help'])) {
            $this->printHelp();
            return;
        }
        if (isset($opts['version'])) {
            echo ClientDecoratorInterface::VERSION, PHP_EOL;
            return;
        }
        $this->job($opts);
    }

    /**
     * Before `verifier` executing, decrypt and put the platform certificate(s) into the `$certs` reference.
     *
     * @param string $apiv3Key
     * @param (null|string)[] $certs
     *
     * @return callable(ResponseInterface)
     */
    private static function certsInjector(string $apiv3Key, array &$certs): callable {
        return static function(ResponseInterface $response) use ($apiv3Key, &$certs): ResponseInterface {
            $body = $response->getBody()->getContents();
            /** @var object{data:array<object{encrypt_certificate:object{serial_no:string,nonce:string,associated_data:string}}>} $json */
            $json = Utils::jsonDecode($body);
            \array_map(static function($row) use ($apiv3Key, &$certs) {
                $cert = $row->encrypt_certificate;
                $certs[$row->serial_no] = AesGcm::decrypt($cert->ciphertext, $apiv3Key, $cert->nonce, $cert->associated_data);
            }, \is_object($json) && isset($json->data) && \is_array($json->data) ? $json->data : []);

            return $response;
        };
    }

    /**
     * @param array<string,string|true> $opts
     *
     * @return void
     */
    private function job(array $opts): void
    {
        static $certs = ['any' => null];

        $outputDir = $opts['output'] ?? \sys_get_temp_dir();
        $apiv3Key = (string) $opts['key'];

        $instance = Builder::factory([
            'mchid'      => $opts['mchid'],
            'serial'     => $opts['serialno'],
            'privateKey' => \file_get_contents((string)$opts['privatekey']),
            'certs'      => &$certs,
            'base_uri'   => $opts['baseuri'] ?? 'https://api.mch.weixin.qq.com/',
        ]);

        $handler = $instance->getDriver()->select(ClientDecoratorInterface::JSON_BASED)->getConfig('handler');
        // The response middle stacks were executed one by one on `FILO` order.
        $handler->after('verifier', Middleware::mapResponse(static::certsInjector($apiv3Key, $certs)), 'injector');
        $handler->before('verifier', Middleware::mapResponse(static::certsRecorder((string) $outputDir, $certs)), 'recorder');

        $instance->chain('v3/certificates')->getAsync(
            ['debug' => true]
        )->otherwise(static function($exception) {
            echo $exception->getMessage(), PHP_EOL;
            if ($exception instanceof RequestException && $exception->hasResponse()) {
                /** @var \Psr\Http\Message\ResponseInterface $body */
                $body = $exception->getResponse();
                echo $body->getBody()->getContents(), PHP_EOL, PHP_EOL, PHP_EOL;
            }
            echo $exception->getTraceAsString(), PHP_EOL;
        })->wait();
    }

    /**
     * After `verifier` executed, wrote the platform certificate(s) onto disk.
     *
     * @param string $outputDir
     * @param (null|string)[] $certs
     * @return callable(ResponseInterface)
     */
    private static function certsRecorder(string $outputDir, array &$certs): callable {
        return static function(ResponseInterface $response) use ($outputDir, &$certs): ResponseInterface {
            $body = $response->getBody()->getContents();
            /** @var object{data:array<object{effective_time:string,expire_time:string:serial_no:string}>} $json */
            $json = Utils::jsonDecode($body);
            $data = \is_object($json) && isset($json->data) && \is_array($json->data) ? $json->data : [];
            \array_walk($data, static function($row, $index, $certs) use ($outputDir) {
                $serialNo = $row->serial_no;
                $outpath = $outputDir . DIRECTORY_SEPARATOR . 'wechatpay_' . $serialNo . '.pem';

                echo 'Certificate #', $index, ' {', PHP_EOL;
                echo '    Serial Number: ', $serialNo, PHP_EOL;
                echo '    Not Before: ', (new DateTime($row->effective_time))->format(DateTime::W3C), PHP_EOL;
                echo '    Not After: ', (new DateTime($row->expire_time))->format(DateTime::W3C), PHP_EOL;
                echo '    Saved to: ', $outpath, PHP_EOL;
                echo '    Content: ', PHP_EOL, PHP_EOL, $certs[$serialNo], PHP_EOL, PHP_EOL;
                echo '}', PHP_EOL;

                \file_put_contents($outpath, $certs[$serialNo]);
            }, $certs);

            return $response;
        };
    }

    /**
     * @return ?array<string,string|true>
     */
    private function parseOpts(): ?array
    {
        $opts = [
            [ 'key', 'k', true ],
            [ 'mchid', 'm', true ],
            [ 'privatekey', 'f', true ],
            [ 'serialno', 's', true ],
            [ 'output', 'o', false ],
            // baseuri can be one of 'https://api2.mch.weixin.qq.com/', 'https://apihk.mch.weixin.qq.com/'
            [ 'baseuri', 'u', false ],
        ];

        $shortopts = 'hV';
        $longopts = [ 'help', 'version' ];
        foreach ($opts as $opt) {
            list($key, $alias) = $opt;
            $shortopts .= $alias . ':';
            $longopts[] = $key . ':';
        }
        $parsed = \getopt($shortopts, $longopts);

        if (!$parsed) {
            return null;
        }

        $args = [];
        foreach ($opts as $opt) {
            list($key, $alias, $mandatory) = $opt;
            if (isset($parsed[$key]) || isset($parsed[$alias])) {
                $possiable = $parsed[$key] ?? $parsed[$alias] ?? '';
                $args[$key] = (string) (is_array($possiable) ? $possiable[0] : $possiable);
            } elseif ($mandatory) {
                return null;
            }
        }

        if (isset($parsed['h']) || isset($parsed['help'])) {
            $args['help'] = true;
        }
        if (isset($parsed['V']) || isset($parsed['version'])) {
            $args['version'] = true;
        }
        return $args;
    }

    private function printHelp(): void
    {
        echo <<<EOD
Usage: 微信支付平台证书下载工具 [-hV]
                    -f=<privateKeyFilePath> -k=<apiv3Key> -m=<merchantId>
                    -s=<serialNo> -o=[outputFilePath] -u=[baseUri]
  -m, --mchid=<merchantId>   商户号
  -s, --serialno=<serialNo>  商户证书的序列号
  -f, --privatekey=<privateKeyFilePath>
                             商户的私钥文件
  -k, --key=<apiv3Key>       API v3密钥
  -o, --output=[outputFilePath]
                             下载成功后保存证书的路径，可选参数，默认为临时文件目录夹
  -u, --baseuri=[baseUri]    接入点，默认为 https://api.mch.weixin.qq.com/
  -V, --version              Print version information and exit.
  -h, --help                 Show this help message and exit.

EOD;
    }
}

// main
(new CertificateDownloader())->run();
