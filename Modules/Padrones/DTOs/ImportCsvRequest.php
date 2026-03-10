<?php

namespace Modules\Padrones\DTOs;

use CodeIgniter\HTTP\Files\UploadedFile;

class ImportCsvRequest 
{
    public string $catalogo_padron_id; 
    public UploadedFile $archivo; 

    public function __construct(string $padronId, UploadedFile $archivo)
    {
        $this->catalogo_padron_id = $padronId;
        $this->archivo            = $archivo;
    }

    
    public function isValid(): bool
    {
        if (!$this->archivo->isValid() || $this->archivo->hasMoved()) {
            return false;
        }

        $extension = strtolower($this->archivo->getClientExtension());
        $extensionesPermitidas = ['csv', 'txt', 'xlsx', 'xls'];
        
        $mime = $this->archivo->getMimeType();
        $mimesValidos = [
            'text/csv', 
            'text/plain',
            'application/csv',
            'application/vnd.ms-excel', 
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' ,
            'application/octet-stream',
            'application/zip'
        ];

        return in_array($extension, $extensionesPermitidas) && in_array($mime, $mimesValidos);
    }

    public function getTempPath(): string
    {
        return $this->archivo->getTempName();
    }

  
    public function getExtensionOriginal(): string
    {
        return strtolower($this->archivo->getClientExtension());
    }
}