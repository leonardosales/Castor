<?php

namespace Castor;

require_once 'Castor/SCSP.php';
require_once 'Castor/Cluster.php';
require_once 'Castor/Exception.php';

/**
 * Description of Castor\Object
 *
 * @author leosales
 */
class Object
{

    private $cluster = NULL;
    private $nodeIP = NULL;
    private $isNamed = NULL;
    private $isMutable = NULL;
    private $bucketName = NULL;
    private $objectName = NULL;
    private $paths = array();
    private $queryParams = array();
    private $scsp = NULL;
    private $content = NULL;
    private $contentType = NULL;
    private $numReplicas = 0;
    private $age = NULL;
    private $date = NULL;
    private $lifepoints = array();
    private $requestDate = NULL;
    private $scspInfoResponse = NULL;
    private $contentLength = NULL;

    public function __construct($uri)
    {
        try {
            if (!is_string($uri) AND $uri == "/") {
                throw new \Castor\Exception(htmlentities("URI inválida."), 404);
            }
            $parsedUrl = parse_url($uri);
            if (!$parsedUrl) {
                throw new \Castor\Exception(htmlentities("URI inválida."), 404);
            }
            $this->cluster = new \Castor\Cluster();
            $this->nodeIP = $this->cluster->getPanNode();
            $excludes = array();
            while (!$this->testConnection()) {
                $excludes = array_merge($excludes, array($this->nodeIP));
                $this->nodeIP = $this->cluster->getNode($excludes);
                if ($this->nodeIP === FALSE) {
                    throw new \Castor\Exception(htmlentities("Nenhum nó disponivel."), 500);
                }
            }
            $this->queryParams = array(
                'validate' => 'yes',
                'checkintegrity' => 'yes',
                'countreps' => "yes",
                'examine' => "yes"
            );
            if (isset($parsedUrl['query'])) {
                $queryPieces = explode("&", $parsedUrl['query']);
                foreach ($queryPieces as $param) {
                    $queryParam = explode("=", $param);
                    if (count($queryParam) == 1) {
                        $this->queryParams[$queryParam[0]] = "yes";
                    } else if (count($queryParam) == 2) {
                        $this->queryParams[$queryParam[0]] = $queryParam[1];
                    }
                }
            }
            if (isset($parsedUrl['path'])) {
                if ($parsedUrl['path'][0] !== "/") {
                    $parsedUrl['path'] = "/" . $parsedUrl['path'];
                }

                $paths = explode("/", $parsedUrl['path']);
                foreach ($paths as $key => $value) {
                    if (empty($paths[$key + 1])) {
                        unset($paths[$key + 1]);
                        continue;
                    }
                    break;
                }
                $this->paths = array_values($paths);
            } else {
                $this->paths = array();
            }
            $this->scsp = new \Castor\SCSP($this->getURI());
            $this->readContent();
        } catch (\HttpException $e) {
            echo $e->getMessage();
        }
    }

    public function getMetadatas()
    {
        $headers = $this->scspInfoResponse['headers'];
        $metadatas = array();
        $matches = array();
        foreach ($headers as $header_name => $header_value) {
            preg_match("/^x-([a-z0-9]+)-meta-([a-z0-9-]+)/i", $header_name, $matches);
            if (count($matches) > 0) {
                $namespace = strtolower($matches[1]);
                $nome = strtolower($matches[2]);
                $metadatas[$namespace][$nome] = $header_value;
            }
        }
        return $metadatas;
    }

    public function getMetadata($namespace, $name)
    {
        $namespace = ucfirst(strtolower($namespace));
        $name = str_replace([' '], ['-'], ucwords(strtolower(str_replace(['_', '-'], [' ', ' '], $name))));
        $metadata = !isset($this->scspInfoResponse['headers']["X-$namespace-Meta-$name"]) ? false : $this->scspInfoResponse['headers']["X-$namespace-Meta-$name"];
        if (!empty($metadata)) {
            return $metadata;
        }
        return FALSE;
    }

    public function getName()
    {
        if ($this->isNamedObject()) {
            return $this->objectName;
        }
        return FALSE;
    }

    public function getUUID()
    {
        if ($this->isUnnamedObject()) {
            return $this->objectName;
        }
        return FALSE;
    }

    public function delete()
    {
        $scspDeleteResponse = $this->scsp->delete();
        if (in_array($scspDeleteResponse['info']['http_code'], array(301, 307))) {
            $this->setURI($scspDeleteResponse['headers']['Location']);
            $this->scsp->setUrl($this->getURI());
            $scspDeleteResponse = $this->scsp->delete();
        }
        if ($scspDeleteResponse['info']['http_code'] != 200) {
            throw new \Castor\Exception($scspDeleteResponse['body'], $scspDeleteResponse['info']['http_code']);
        }
        return TRUE;
    }

    public function update()
    {
        if ($this->isMutable) {
            $this->scsp->setUrl($this->getURI());
            $headers = $this->getIntegrityAndMimeHeaders();
            $scspUpdateResponse = $this->scsp->update($this->content, $headers);
            while (in_array($scspUpdateResponse['info']['http_code'], array(301, 307))) {
                $this->setURI($scspUpdateResponse['headers']['Location']);
                $this->scsp->setUrl($this->getURI());
                $scspUpdateResponse = $this->scsp->update($this->content, $headers);
            }
            if ($scspUpdateResponse['info']['http_code'] != 201) {
                throw new \Castor\Exception($scspUpdateResponse['body'], $scspUpdateResponse['info']['http_code']);
            }
            return TRUE;
        } else {
            throw new \Castor\Exception("<html><body><h2>CAStor Error</h2><br>Trying to update a immutable object.</body></html>", 400);
        }
    }

    public function info()
    {
        return $this->scsp->info();
    }

    public function append($content = NULL)
    {
        if ($this->isMutable) {
            $headers = $this->getIntegrityAndMimeHeaders($content);
            $headers['Transfer-Encoding'] = 'chunked';
            $this->scsp->setUrl($this->getURI());
            $scspAppendResponse = $this->scsp->append($this->content, $headers);
            while (in_array($scspAppendResponse['info']['http_code'], array(301, 307))) {
                $this->setURI($scspAppendResponse['headers']['Location']);
                $this->scsp->setUrl($this->getURI());
                $scspAppendResponse = $this->scsp->append($this->content, $headers);
            }
            if ($scspAppendResponse['info']['http_code'] != 200) {
                throw new \Castor\Exception($scspAppendResponse['body'], $scspAppendResponse['info']['http_code']);
            }
            return $scspAppendResponse;
        } else {
            throw new \Castor\Exception("<html><body><h2>CAStor Error</h2><br>Trying to update a immutable object.</body></html>", 400);
        }
    }

    public function getContent()
    {
        return $this->doRead();
    }

    public function setContent($content)
    {
        $this->content = $content;
    }

    public function setContentByFile(\SplFileInfo $fileinfo)
    {
        $content = $this->readfile_chunked($fileinfo->getRealPath(), FILE_BINARY);
        $this->content = $content;
    }

    public function getContentType()
    {
        return $this->contentType;
    }

    public function getCountReplicas()
    {
        return $this->numReplicas;
    }

    public function getDate()
    {
        return $this->date;
    }

    public function getRequestDate()
    {
        return $this->requestDate;
    }

    public function isImmutable()
    {
        return $this->isMutable === FALSE;
    }

    public function isMutable()
    {
        return $this->isMutable === TRUE;
    }

    public function isNamedObject()
    {
        return $this->isNamed === TRUE;
    }

    public function isUnnamedObject()
    {
        return $this->isNamed === FALSE;
    }

    private function getURI()
    {
        return http_build_url('', array(
            'scheme' => "http",
            'host' => $this->nodeIP,
            'path' => $this->getPath(),
            'query' => $this->getQuery()
        ));
    }

    private function setURI($uri)
    {
        $parsedUri = \parse_url($uri);
        if ($parsedUri) {
            $this->nodeIP = $parsedUri['host'];
            $this->paths = explode("/", $parsedUri['path']);
            parse_str($parsedUri['query'], $this->queryParams);
        } else {
            throw new \Castor\Exception("URI inválida.", 404);
        }
        return $this->getURI();
    }

    private function getPath()
    {
        return implode("/", $this->paths);
    }

    public function getLifecycles()
    {
        return $this->lifepoints;
    }

    private function getQuery()
    {
        return \http_build_query($this->queryParams);
    }

    private function setMutable()
    {
        $this->isMutable = TRUE;
    }

    private function setImmutable()
    {
        $this->isMutable = FALSE;
    }

    private function setNamed()
    {
        $this->isNamed = TRUE;
    }

    private function setUnnamed()
    {
        $this->isNamed = FALSE;
    }

    private function getBucketName()
    {
        if ($this->isNamedObject()) {
            return $this->bucketName;
        }
        return FALSE;
    }

    private function setBucketName($bucketName)
    {
        if ($this->isNamedObject()) {
            $this->bucketName = $bucketName;
        }
    }

    private function setObjectName($objectName)
    {
        $this->objectName = $objectName;
    }

    private function parseLifepoint($lifepoint)
    {
        $posIniBracket = strpos($lifepoint, "[");
        $posEndBracket = strpos($lifepoint, "]");
        $strConstraints = trim(substr($lifepoint, $posEndBracket + 1));
        $strDate = substr($lifepoint, $posIniBracket + 1, $posEndBracket - $posIniBracket - 1);
        if (!empty($strConstraints)) {
            $constraints = explode(",", $strConstraints);
            if (count($constraints) == 1) {
                $replicationConstraint = explode("=", $constraints[0]);
                $deletionConstraint = array();
            } else if (count($constraints) == 2) {
                $replicationConstraint = explode("=", $constraints[0]);
                $deletionConstraint = explode("=", $constraints[1]);
            }
        }
        if (!empty($strDate)) {
            $date = new \DateTime($strDate);
            $date->setTimezone(new \DateTimeZone(\date_default_timezone_get()));
        }
        return $lifepoint;
    }

    public function getIntegrityAndMimeHeaders($aditionalContent = NULL)
    {
        $headers = array();
        $content = $this->content . (empty($aditionalContent) ? "" : $aditionalContent);
        $headers['Content-MD5'] = base64_encode(md5($content, TRUE));
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_buffer($finfo, $content);
        finfo_close($finfo);
        $headers['Content-Type'] = $mime;
        return $headers;
    }

    public function testConnection()
    {
        $fp = @fsockopen($this->nodeIP, 80, $errno, $errstr, 0.1);
        if (!$fp) {
            return FALSE;
        }
        return TRUE;
    }

    private function human_filesize($bytes, $decimals = 0)
    {
        $size = array('B', 'K', 'M', 'G', 'TB', 'PB', 'EB', 'ZB', 'YB');
        $factor = floor((strlen($bytes) - 1) / 3);
        return (sprintf("%.{$decimals}f", round($bytes / pow(1024, $factor)) * 4)) . @$size[$factor];
    }

    private function readContent()
    {
        $this->scspInfoResponse = $this->scsp->info();
        if ($this->scspInfoResponse['info']['http_code'] === 403) {
            $this->queryParams['alias'] = "yes";
            $this->scsp->setUrl($this->getURI());
            $this->scspInfoResponse = $this->scsp->info();
        }

        $this->contentLength = isset($this->scspInfoResponse['headers']['Content-Length']) ? $this->scspInfoResponse['headers']['Content-Length'] : (isset($this->scspInfoResponse['headers']['X-Original-Content-Length']) ? $this->scspInfoResponse['headers']['X-Original-Content-Length'] : 0);
        while (in_array($this->scspInfoResponse['info']['http_code'], array(301, 307))) {
            $this->setURI($this->scspInfoResponse['headers']['Location']);
            $this->scsp->setUrl($this->getURI());
            $this->scspInfoResponse = $this->scsp->info();
            if ($this->scspInfoResponse['info']['http_code'] === 403) {
                $this->queryParams['alias'] = "yes";
                $this->scsp->setUrl($this->getURI());
                $this->scspInfoResponse = $this->scsp->info();
            }
        }
        if ($this->scspInfoResponse['info']['http_code'] === 200) {
            // Sucesso
            $this->headers = $this->scspInfoResponse['headers'];
            $tamanhoConteudo = $this->contentLength;
            $CID = isset($this->scspInfoResponse['headers']['Castor-System-CID']) ? $this->scspInfoResponse['headers']['Castor-System-CID'] : "";
            $age = isset($this->scspInfoResponse['headers']['Age']) ? $this->scspInfoResponse['headers']['Age'] : "";
            $date = isset($this->scspInfoResponse['headers']['Castor-System-Created']) ? $this->scspInfoResponse['headers']['Castor-System-Created'] : "";
            $requestDate = isset($this->scspInfoResponse['headers']['Date']) ? $this->scspInfoResponse['headers']['Date'] : "";
            $lifepoints = isset($this->scspInfoResponse['headers']['Lifepoint']) ? $this->scspInfoResponse['headers']['Lifepoint'] : "";
            $version = isset($this->scspInfoResponse['headers']['Castor-System-Version']) ? $this->scspInfoResponse['headers']['Castor-System-Version'] : "";
            $this->isMutable = !empty($version);
            $this->isNamed = !empty($CID);
            $this->age = !empty($age) ? (int)$age : NULL;
            if (!empty($date)) {
                $dataCriacao = new \DateTime($date);
                $dataCriacao->setTimezone(new \DateTimeZone(\date_default_timezone_get()));
            } else {
                $dataCriacao = NULL;
            }
            $this->date = $dataCriacao;
            if (!empty($requestDate)) {
                $dataRequisicao = new \DateTime($date);
                $dataRequisicao->setTimezone(new \DateTimeZone(\date_default_timezone_get()));
            } else {
                $dataRequisicao = NULL;
            }
            $this->requestDate = $dataRequisicao;
            if (!empty($lifepoints)) {
                if (is_array($lifepoints)) {
                    foreach ($lifepoints as $i => $lifepoint) {
                        $this->lifepoints[$i] = $this->parseLifepoint($lifepoint);
                    }
                } else {
                    $this->lifepoints[] = $this->parseLifepoint($lifepoints);
                }
            }

            if ($this->isNamedObject()) {
                $this->setObjectName($this->scspInfoResponse['headers']['Castor-System-Name']);
                $piecesPath = explode("/", $this->scspInfoResponse['headers']['Castor-System-Path']);
                $this->setBucketName($piecesPath[2]);
            } else {
                $this->setObjectName($this->paths[1]);
            }
            $this->contentType = $this->scspInfoResponse['info']['content_type'];
            if ($this->scspInfoResponse) {
                $numReps = $this->scspInfoResponse['headers']['Replica-Count'];
                $this->numReplicas = !empty($numReps) ? (int)$numReps : NULL;
            }
        } elseif ($this->scspInfoResponse['info']['http_code'] === 404) {
            throw new \Castor\Exception("Object Not Found", $this->scspInfoResponse['info']['http_code']);
        } else {
            throw new \Castor\Exception("Error getting information", $this->scspInfoResponse['info']['http_code']);
        }
    }

    private function doRead()
    {
        $scspReadResponse = $this->scsp->read();
        $remoteSign = isset($scspReadResponse['headers']['Content-Md5']) ? $scspReadResponse['headers']['Content-Md5'] : "";
        if (!empty($remoteSign)) {
            $localSign = base64_encode(md5($scspReadResponse['body'], TRUE));
            if ($remoteSign !== $localSign) {
                throw new \Castor\Exception("<html><body><h2>CAStor Error</h2><br>Content-MD5 did not match computed digest</body></html>", 400);
            }
        }
        return $scspReadResponse['body'];
    }

    private function readfile_chunked($filename)
    {
        $chunksize = 1 * (1024 * 1024); // how many bytes per chunk
        $buffer = '';
        $handle = fopen($filename, 'rb');
        if ($handle === FALSE) {
            return FALSE;
        }
        while (!feof($handle)) {
            $buffer .= stream_get_line($handle, $chunksize);
        }
        fclose($handle);
        return $buffer;
    }

    public function getContentLength()
    {
        return intval($this->contentLength);
    }

}
