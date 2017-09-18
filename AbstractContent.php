<?php

namespace Castor;

/**
 * Description of AbstractObject
 *
 * @author leonardo
 */
class AbstractContent
{

    protected $queryParams = array();
    protected $headers = array();
    protected $lifepoints = array();
    protected $scsp = NULL;
    protected $cluster = NULL;
    protected $bucket;
    protected $path;
    protected $name;
    protected $lifepointAutodelete;

    public function setMutable($isMutable)
    {
        if ($isMutable) {
            $this->queryParams['alias'] = "yes";
        } else {
            if (isset($this->queryParams['alias'])) {
                unset($this->queryParams['alias']);
            }
        }
    }

    public function setImmutable($isImmutable)
    {
        if ($isImmutable) {
            if (isset($this->queryParams['alias'])) {
                unset($this->queryParams['alias']);
            }
        } else {
            $this->queryParams['alias'] = "yes";
        }
    }

    public function isImmutable()
    {
        return !isset($this->queryParams['alias']);
    }

    public function isMutable()
    {
        return isset($this->queryParams['alias']);
    }

    public function setReplicateImmediate($replicateImmediate)
    {
        if ($replicateImmediate) {
            $this->queryParams['replicate'] = "immediate";
        } else {
            if (isset($this->queryParams['replicate'])) {
                unset($this->queryParams['replicate']);
            }
        }
    }

    public function getReplicateImmediate()
    {
        return isset($this->queryParams['replicate']);
    }

    public function getCheckIntegrity()
    {
        return isset($this->headers['Content-MD5']);
    }

    public function addMetadata($namespace, $name, $value)
    {
        $namespace = ucfirst(str_replace(array(" ", "-"), "", $namespace));
        $name = ucfirst(str_replace(array(" ", "-"), "", $name));
        $this->headers["x-$namespace-meta-$name"] = $value;
    }

    public function removeMetadata($namespace, $name)
    {
        $namespace = ucfirst(str_replace(array(" ", "-"), "", $namespace));
        $name = ucfirst(str_replace(array(" ", "-"), "", $name));
        if (isset($this->headers["x-$namespace-meta-$name"])) {
            unset($this->headers["x-$namespace-meta-$name"]);
            return TRUE;
        }
        return FALSE;
    }

    public function isAutoDelete()
    {
        return $this->lifepointAutodelete;
    }

    public function setAutoDelete($isAutoDelete)
    {
        $this->lifepointAutodelete = (bool)$isAutoDelete;
    }

    public function addLifecycle(\DateTime $endDate, $replicationConstraint = NULL, $deletionConstraint = NULL)
    {
        $dateGMT = $endDate->setTimezone(new \DateTimeZone("GMT"));
        $this->lifepoints[] = new \Castor\Lifecycle($dateGMT, $replicationConstraint, $deletionConstraint);
    }

    private function getLifecycles()
    {
        return $this->lifepoints;
    }

//    public function save()
//    {
//        if (count($this->lifepoints) == 0 AND $this->lifepointAutodelete) {
//            throw new \Castor\Exception("Não é possivel definir 'auto delete' antes da definição de um ciclo de vida.");
//        }
//        $this->scsp->setUrl($this->getCasURI());
//        $this->content = $this->readfile_chunked();
//        $this->addHeadersIntegrityAndMime();
//        $this->addHeadersLifecycles();
//        $scspWriteResponse = $this->scsp->write($this->content, $this->getCasHeaders());
//        if (in_array($scspWriteResponse['info']['http_code'], array(301, 307))) {
//            $redirect = $scspWriteResponse['headers']["Location"];
//            $this->scsp->setUrl($redirect);
//            $scspWriteResponse = $this->scsp->write($this->content, $this->getCasHeaders());
//        }
//        if ($scspWriteResponse['info']['http_code'] == 201) {
//            $location = $scspWriteResponse['headers']['Location'];
//            return new \Castor\Object($location[0]);
//        } else {
//            throw new \Castor\Exception($scspWriteResponse['body'], $scspWriteResponse['info']['http_code']);
//        }
//    }

    protected function addHeadersIntegrityAndMime()
    {
        if ($this->getCheckIntegrity()) {
            $this->headers['Content-MD5'] = base64_encode(md5($this->content, TRUE));
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_buffer($finfo, $this->content);
        $this->headers['Content-Type'] = $mime;
    }

    protected function addHeadersLifecycles()
    {
        if (count($this->getLifecycles()) > 0) {
            $i = 0;
            foreach ($this->getLifecycles() as $lifecycle) {
                $this->headers['Lifepoint' . str_repeat(" ", $i++)] = $lifecycle;
            }
        }
        if ($this->isAutoDelete()) {
            $this->headers['Lifepoint' . str_repeat(" ", $i)] = "[] delete";
        }
    }

    public function getCasHeaders()
    {
        return $this->headers;
    }

    protected function getCasQuery()
    {
        return http_build_query($this->queryParams);
    }

    protected function getCasURI()
    {
        return http_build_url('', array(
            'scheme' => "http",
            'host' => $this->cluster->getPanNode(),
            'path' => $this->getCasPath(),
            'query' => $this->getCasQuery()
        ));
    }

    protected function bucketExists()
    {
        $scsp = new \Castor\SCSP($this->cluster->getPanNode() . $this->bucket . $this->getCasQuery());
        return ($scsp->read()->getResponseCode() === 200);
    }

    protected function createBucket()
    {
        $scsp = new \Castor\SCSP($this->cluster->getPanCluster() . urlencode($this->bucket) . $this->getCasQuery());
    }

    public function getCasPath()
    {
        return $this->path;
    }
}
