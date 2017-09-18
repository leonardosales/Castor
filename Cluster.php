<?php

namespace Castor;

/**
 * Description of CastorCluster
 *
 * @author leosales
 */
class Cluster {

//    private $PAN = array("172.30.0.89");
    private $PAN = array("tdinfocascluster.tce-to.tce.to.gov.br");
    private $SAN = array("172.30.0.90", "172.30.0.91");
    private $urlCluster = "";
    private $casNodes;

    public function __construct()
    {
        $this->casNodes = array_merge($this->PAN, $this->SAN);
    }

    public function getPanNode()
    {
        $panNode = $this->PAN[0];
        while (substr($panNode, -1) === "/") {
            $panNode = substr($panNode, 0, -1);
        }
        $excludes = array();
        while (!$this->service_ping($panNode, 80)) {
            $excludes[] = $panNode;
            if (count($excludes) == count($this->casNodes)) {
                throw new \Castor\Exception("Impossivel conectar a um nó do CAStor. Tentantivas em: " . implode(", ", $excludes), 500);
            }
            $panNode = $this->getNode($excludes);
        }
        return $panNode;
    }

    public function getNode($excludes = NULL)
    {
        if (is_array($excludes)) {
            return current(array_diff_assoc($this->casNodes, $excludes));
        }
    }

    private function service_ping($host, $port = 389, $timeout = 0.01)
    {
        $op = @\fsockopen($host, $port, $errno, $errstr, $timeout);
        if (!$op) {
            return FALSE;
        } else {
            fclose($op);
            return TRUE;
        }
    }

}
