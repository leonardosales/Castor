<?php

namespace Castor;

/**
 * Description of Exception
 *
 * @author leosales
 */
class Lifecycle
{

    const DEFAULT_NUM_REPLICAS = 2;

    private $replicationConstraints = array();
    private $deletionConstraints = array();

    public function __construct($endDateGMT, $replicationConstraint = NULL, $deletionConstraint = NULL)
    {
        $this->endDate = $endDateGMT;
        $defaultReplicationConstraint = array("replicas" => self::DEFAULT_NUM_REPLICAS);
        $defaultDeletionConstraint = array("deletable" => FALSE);
        if (is_array($replicationConstraint)) {
            $this->replicationConstraints = array_merge($defaultReplicationConstraint, array_intersect_key($replicationConstraint, $defaultReplicationConstraint));
        } else {
            $this->replicationConstraints = $defaultReplicationConstraint;
        }
        if (is_array($deletionConstraint)) {
            $this->deletionConstraints = array_merge($defaultDeletionConstraint, array_intersect_key($deletionConstraint, $defaultDeletionConstraint));
        } else {
            $this->deletionConstraints = $defaultDeletionConstraint;
        }
//        $this->deletionConstraints['deletable'] = (bool)$this->isDeletable();
    }

    public function __toString()
    {
        $headerLifecycle = "[" . $this->endDate->format('D, d M Y H:i:s') . " GMT" . "]";
        if (isset($this->replicationConstraints['replicas'])) {
            if ($this->replicationConstraints['replicas'] >= 1) {
                $headerLifecycle .= " reps=" . $this->replicationConstraints['replicas'];
            } else {
                $headerLifecycle .= " reps=" . self::DEFAULT_NUM_REPLICAS;
            }
        }
        if ($this->isDeletable()) {
            $headerLifecycle .= ", deletable=yes";
        } else {
            $headerLifecycle .= ", deletable=no";
        }
        return $headerLifecycle;
    }

    public function getNumReplicas()
    {
        return $this->replicationConstraints["replicas"];
    }

    public function setNumReplicas($numReplicas)
    {
        $this->replicationConstraints["replicas"] = $numReplicas;
    }


    public function isDeletable($isDeletable = null)
    {
        if (is_bool($isDeletable)) {
            $this->deletionConstraints["deletable"] = $isDeletable;
        }
        return (bool)$this->deletionConstraints["deletable"];
    }

}