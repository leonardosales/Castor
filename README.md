Castor
======

A set of PHP classes that allow the creation, updating, deleting and retrieval of information objects stored in a CAS system (Castor), of the company Caringo.

To store one object into CAStor you can use a Castor\File or Castor\Buffer, when File is a path to one file and Buffer is a couple of bytes to be sent.

Examples of usage:
==================

    public function post()
    {
        try {
            $metadados = [];
            $file = new \Castor\File("/var/www/fpdi/151MB.pdf");
            $file = new \Castor\Buffer("Teste NetApp");
            $file->setReplicateImmediate(FALSE); // TRUE by default
            $file->setMutable(FALSE); // FALSE by default
            $file->setImmutable(FALSE); // TRUE by default
            $file->setCheckIntegrity(FALSE); // TRUE by default
            $file->addLifecycle(new \DateTime("2100-05-22 16:00:00"), ["replicas" => 3], ['deletable' => FALSE]);
            $file->setAutoDelete(TRUE); // FALSE by default
            $file->addLifecycle(new \DateTime("2100-01-01 15:25:00"), NULL, ['deletable' => FALSE]);
            $file->addMetadata("TCE", "NomeArquivo", "Teste.txt");
            $casObj = $arquivo->save();
            return [
                "uuid" => $casObj->getUUID(),
                "nome" => $casObj->getName(),
                "conteudo" => $casObj->getContent(),
                "tamanho_conteudo" => strlen($casObj->getContent()),
                "mime_type" => $casObj->getContentType(),
                "num_replicas" => $casObj->getCountReplicas(),
                "data_criacao" => $casObj->getDate(),
                "data_requisicao" => $casObj->getRequestDate(),
                "eh_mutavel?" => $casObj->isMutable(),
                "eh_imutavel?" => $casObj->isImmutable(),
                "objeto_com_nome?" => $casObj->isNamedObject(),
                "objeto_sem_nome?" => $casObj->isUnnamedObject(),
                "metadados" => $casObj->getMetadata("TCE", 'NomeArquivo'),
                "ciclo_vida" => $casObj->getLifecycles()
            ];
        } catch (\Castor\Exception $e) {
            return $e->getCode() . " - " . $e->getMessage();
        }
    }
