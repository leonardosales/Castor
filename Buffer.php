<?php

namespace Castor;

require_once 'Castor/SCSP.php';
require_once 'Castor/Object.php';
require_once 'Castor/Lifecycle.php';
require_once 'Castor/Exception.php';

/**
 * Description of Castor\File
 *
 * @author leosales
 */
class Buffer extends AbstractContent
{
    public function __construct($content, $bucket = NULL, $name = NULL, $domain = NULL)
    {
        try {
            $this->queryParams = array(
                'replicate' => 'immediate'
            );

            $this->content = $content;
            $this->cluster = new \Castor\Cluster();
            $this->scsp = new \Castor\SCSP($this->getCasURI());
            if (!empty($domain)) {
                $this->queryParams['domain'] = $domain;
            }
            if (!empty($bucket) AND !empty($name) AND !empty($domain)) {
                $domain = trim($domain);
                $bucket = trim($bucket);
                $name = trim($name);

                $basename = $this->getBasename();
                if (empty($basename)) {
                    throw new \Exception('Nome de arquivo vazio.');
                }
                while ($name[0] === "/") {
                    $name = substr($name, 1);
                }
                $pathinfo = pathinfo($name);
                if (isset($pathinfo['extension'])) {
                    if ($pathinfo['dirname'] === ".") {
                        $this->name = $pathinfo['basename'];
                    } else {
                        $this->name = implode("/", array($pathinfo['dirname'], $pathinfo['basename']));
                    }
                } else {
                    $this->name = implode("/", array($pathinfo['basename'], $this->getBasename()));
                }
                while ($bucket[strlen($bucket) - 1] === "/") {
                    $bucket = substr($bucket, 0, -1);
                }
                while ($bucket[0] === "/") {
                    $bucket = substr($bucket, 1);
                }
                $this->bucket = $bucket;
                $bucketPieces = array(
                    '',
                    $this->bucket,
                    ''
                );
                $this->path = implode("/", $bucketPieces) . $this->name;
            } else {
                $this->path = "/";
            }
            $this->setCheckIntegrity(true);
            $this->setAutoDelete(false);
        } catch (\Exception $exc) {
            die($exc->getMessage());
        }
    }

    public function setCheckIntegrity($check)
    {
        if ($check) {
            $this->headers['Content-MD5'] = base64_encode(md5($this->content, TRUE));
            $this->headers['Expect'] = 'Content-MD5';
        } else {
            if (isset($this->headers['Content-MD5'])) {
                unset($this->headers['Content-MD5']);
                unset($this->headers['Expect']);
            }
        }
    }

    public function save()
    {
        try {
            if (count($this->lifepoints) == 0 AND $this->lifepointAutodelete) {
                throw new \Castor\Exception("Não é possivel definir 'auto delete' antes da definição de um ciclo de vida.", 500);
            }
            if (empty($this->content)) {
                throw new \Castor\Exception("No content defined.", 501);
            }
            $this->scsp->setUrl($this->getCasURI());
            $this->addHeadersIntegrityAndMime();
            $this->addHeadersLifecycles();
            $scspWriteResponse = $this->scsp->write($this->content, $this->getCasHeaders());
            while (in_array($scspWriteResponse['info']['http_code'], array(301, 307))) {
                $redirect = $scspWriteResponse['headers']["Location"];
                $this->scsp->setUrl($redirect);
                $scspWriteResponse = $this->scsp->write($this->content, $this->getCasHeaders());
            }
            if ($scspWriteResponse['info']['http_code'] == 201) {
                $location = $scspWriteResponse['headers']['Location'];
                return new \Castor\Object($location[0]);
            } else {
                throw new \Castor\Exception($scspWriteResponse['body'], $scspWriteResponse['info']['http_code']);
            }
        } catch (\Exception $exc) {
            echo $exc->getMessage();
        }
    }
}
