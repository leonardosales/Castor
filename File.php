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
class File extends AbstractContent// extends \SplFileInfo
{

//    private $queryParams = array();
//    private $headers = array();
//    private $lifepoints = array();
//    private $scsp = NULL;
//    private $cluster = NULL;
//    private $bucket;
//    private $path;
//    private $name;
//    private $lifepointAutodelete;

    private $fileinfo;

    public function __construct($pathToFile, $bucket = NULL, $name = NULL, $domain = NULL)
    {
        try {
            if (!file_exists($pathToFile)) {
                throw new \Exception(htmlentities('Arquivo ' . $pathToFile . ' não existe.'));
            }
            $this->fileinfo = new \SplFileInfo($pathToFile);
            $this->queryParams = array(
                'replicate' => 'immediate'
            );

            $this->cluster = new \Castor\Cluster();
            $this->scsp = new \Castor\SCSP();
            if (!empty($domain)) {
                $this->queryParams['domain'] = $domain;
            }
            if (!empty($bucket) AND !empty($name) AND !empty($domain)) {
                $domain = trim($domain);
                $bucket = trim($bucket);
                $name = trim($name);

                $basename = $this->fileinfo->getBasename();
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
                    $this->name = implode("/", array($pathinfo['basename'], $this->fileinfo->getBasename()));
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

    public function getFilename()
    {
        return $this->fileinfo->getFilename();
    }

    public function getBasename()
    {
        return $this->fileinfo->getBasename();
    }

    public function getRealPath()
    {
        return $this->fileinfo->getRealPath();
    }

    public function setCheckIntegrity($check)
    {
        if ($check) {
            $this->headers['Content-MD5'] = base64_encode(md5($this->readfile_chunked(), TRUE));
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
        if (count($this->lifepoints) == 0 AND $this->lifepointAutodelete) {
            throw new \Castor\Exception("Não é possivel definir 'auto delete' antes da definição de um ciclo de vida.", 500);
        }
        $this->scsp->setUrl($this->getCasURI());
        $this->content = $this->readfile_chunked();
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
    }

    private function readfile_chunked()
    {
        $chunksize = 1 * (1024 * 1024);
        $buffer = '';
        $handle = fopen($this->fileinfo->getRealPath(), 'rb');
        if ($handle === FALSE) {
            return FALSE;
        }
        while (!feof($handle)) {
            $buffer .= stream_get_line($handle, $chunksize);
        }
        fclose($handle);
        return $buffer;
    }

}
