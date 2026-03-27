<?php

namespace Modules\Padrones\Services;

use RuntimeException;
use XMLReader;

class FileConverterService
{
    public function prepararCsv(string $rutaOriginal, string $extension): string
    {
        $ext = strtolower(trim($extension, '.'));

        if (in_array($ext, ['csv', 'txt'], true)) {
            return $rutaOriginal;
        }

        if (in_array($ext, ['xlsx', 'xls'], true)) {
            return $this->xlsxACsv($rutaOriginal);
        }

        throw new RuntimeException("Formato no soportado: {$extension}");
    }

    private function xlsxACsv(string $ruta): string
    {
        if (class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
            return $this->xlsxACsvConPhpSpreadsheet($ruta);
        }

        return $this->xlsxACsvNativo($ruta);
    }

    // ================== PhpSpreadsheet (Para archivos pequeños/complejos) ==================
    private function xlsxACsvConPhpSpreadsheet(string $ruta): string
    {
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($ruta);
        if (method_exists($reader, 'setReadDataOnly')) {
            $reader->setReadDataOnly(true);
        }

        $spreadsheet = $reader->load($ruta);
        $ws = $spreadsheet->getActiveSheet();

        $highRow = $ws->getHighestRow();
        $highCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($ws->getHighestColumn());

        $primeraFila = $this->detectarPrimeraFilaConDatos($ws, $highRow, $highCol);
        if ($primeraFila === null) {
            throw new RuntimeException("El archivo XLSX no contiene datos.");
        }

        // Detectar posible super-header
        $filaHeaders = $primeraFila;
        $filaSuperHeader = null;

        if ($primeraFila > 1) {
            $anterior = $this->leerFila($ws, $primeraFila - 1, $highCol);
            $actual   = $this->leerFila($ws, $primeraFila, $highCol);

            $cntAnt = count(array_filter($anterior, fn($v) => trim($v) !== ''));
            $cntAct = count(array_filter($actual, fn($v) => trim($v) !== ''));

            if ($cntAnt > 0 && $cntAnt < $cntAct) {
                $filaSuperHeader = $primeraFila - 1;
            }
        }

        $headers = $this->construirHeadersFusionados($ws, $filaSuperHeader, $filaHeaders, $highCol);

        $destino = sys_get_temp_dir() . '/padron_' . uniqid() . '.csv';
        $handle = fopen($destino, 'w');

        fputcsv($handle, $headers, ",", "\"", "\\");

        for ($row = $filaHeaders + 1; $row <= $highRow; $row++) {
            $fila = $this->leerFila($ws, $row, $highCol);

            if ($this->filaEstaVacia($fila)) continue;
            if ($this->esFilaBasura($fila)) continue;

            fputcsv($handle, $fila, ",", "\"", "\\");
        }

        fclose($handle);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $destino;
    }

    // ================== Fallback Nativo (Streaming XMLReader para archivos GIGANTES) ==================
    private function xlsxACsvNativo(string $ruta): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($ruta) !== true) {
            throw new RuntimeException("No se pudo abrir el archivo XLSX como ZIP.");
        }

        // 1. EXTRAER TEXTOS COMPARTIDOS USANDO XMLREADER (Streaming para no reventar la RAM)
        $sharedStrings = [];
        $streamShared = $zip->getStream('xl/sharedStrings.xml');
        
        if ($streamShared !== false) {
            $xmlReader = new XMLReader();
            
            // Un pequeño truco para pasar el stream de ZipArchive a XMLReader
            $tempDict = tempnam(sys_get_temp_dir(), 'ss_');
            file_put_contents($tempDict, stream_get_contents($streamShared));
            fclose($streamShared);
            
            $xmlReader->open($tempDict);
            
            while ($xmlReader->read()) {
                if ($xmlReader->nodeType == XMLReader::ELEMENT && $xmlReader->name === 'si') {
                    // Extraer contenido interno del nodo 'si' (SimpleXMLElement está bien aquí porque el nodo es minúsculo)
                    $nodeXml = $xmlReader->readOuterXml();
                    if ($nodeXml) {
                        $si = new \SimpleXMLElement($nodeXml);
                        $texto = '';
                        if (isset($si->t)) {
                            $texto = (string)$si->t;
                        } elseif (isset($si->r)) {
                            foreach ($si->r as $r) {
                                $texto .= (string)$r->t;
                            }
                        }
                        $sharedStrings[] = $texto;
                    }
                }
            }
            $xmlReader->close();
            @unlink($tempDict);
        }

        // 2. EXTRAER HOJA DE DATOS
        $sheetEntryName = 'xl/worksheets/sheet1.xml';
        if ($zip->locateName('xl/worksheets/sheet2.xml') !== false && $zip->locateName($sheetEntryName) === false) {
            $sheetEntryName = 'xl/worksheets/sheet2.xml';
        }

        $streamSheet = $zip->getStream($sheetEntryName);
        if ($streamSheet === false) {
            $zip->close();
            throw new RuntimeException("No se encontró la hoja de datos principal (sheet1.xml) dentro del XLSX.");
        }

        $tempSheet = tempnam(sys_get_temp_dir(), 'sheet_');
        file_put_contents($tempSheet, stream_get_contents($streamSheet));
        fclose($streamSheet);
        $zip->close();

        // 3. PROCESAR HOJA DE DATOS CON STREAMING Y ESCRIBIR AL CSV INMEDIATAMENTE
        $destino = sys_get_temp_dir() . '/padron_' . uniqid() . '.csv';
        $handle = fopen($destino, 'w');

        $xmlReader = new XMLReader();
        $xmlReader->open($tempSheet);

        $headersDetectados = false;
        $maxColGlobal = 0;

        while ($xmlReader->read()) {
            if ($xmlReader->nodeType == XMLReader::ELEMENT && $xmlReader->name === 'row') {
                $nodeXml = $xmlReader->readOuterXml();
                if (!$nodeXml) continue;
                
                $row = new \SimpleXMLElement($nodeXml);
                
                $filaArr = [];
                $maxCol = 0;

                foreach ($row->c as $cell) {
                    $colRef = (string)$cell['r'];
                    // Si no tiene referencia (r), asumimos que es secuencial, pero en XLSX siempre deberían tenerla.
                    $colIdx = $colRef ? $this->colLetraAIndice($colRef) - 1 : count($filaArr);
                    
                    $valXml = isset($cell->v) ? (string)$cell->v : '';
                    $tipo = (string)$cell['t'];

                    $valor = ($tipo === 's')
                        ? ($sharedStrings[(int)$valXml] ?? '')
                        : $valXml;

                    $filaArr[$colIdx] = str_replace(["\r", "\n"], " ", trim((string)$valor));
                    if ($colIdx > $maxCol) $maxCol = $colIdx;
                }

                if ($maxCol > $maxColGlobal) $maxColGlobal = $maxCol;

                // Construir arreglo completo con índices vacíos donde no haya celda
                $filaCompleta = [];
                for ($c = 0; $c <= $maxColGlobal; $c++) {
                    $filaCompleta[] = $filaArr[$c] ?? '';
                }

                if ($this->filaEstaVacia($filaCompleta)) continue;
                if ($this->esFilaBasura($filaCompleta)) continue;

                // Si es la primera fila válida, la tratamos como headers
                if (!$headersDetectados) {
                    $headers = $this->hacerHeadersUnicos($filaCompleta);
                    fputcsv($handle, $headers, ",", "\"", "\\");
                    $headersDetectados = true;
                } else {
                    fputcsv($handle, $filaCompleta, ",", "\"", "\\");
                }
            }
        }

        $xmlReader->close();
        fclose($handle);
        @unlink($tempSheet);

        if (!$headersDetectados) {
            throw new RuntimeException("El archivo Excel no contenía filas con datos válidos.");
        }

        return $destino;
    }

    // ================== Helpers ==================

    private function leerFila($ws, int $row, int $highCol): array
    {
        $fila = [];

        for ($col = 1; $col <= $highCol; $col++) {
            $valor = $ws->getCell([$col, $row])->getFormattedValue();
            $fila[] = str_replace(["\r", "\n"], " ", trim((string)$valor));
        }

        return $fila;
    }

    private function construirHeadersFusionados($ws, ?int $filaSuperHeader, int $filaHeaders, int $maxCol): array
    {
        $headers = [];
        $superActual = null;

        for ($col = 1; $col <= $maxCol; $col++) {

            $superVal = $filaSuperHeader
                ? trim((string)$ws->getCell([$col, $filaSuperHeader])->getValue())
                : '';

            $subVal = trim((string)$ws->getCell([$col, $filaHeaders])->getValue());

            if ($superVal !== '') {
                $superActual = $superVal;
            }

            if ($subVal !== '') {
                $headers[] = $superActual && $superVal === ''
                    ? "{$superActual} - {$subVal}"
                    : $subVal;
            } else {
                $headers[] = $superActual ?? "columna_{$col}";
            }
        }

        return $this->hacerHeadersUnicos($headers);
    }

    private function hacerHeadersUnicos(array $headers): array
    {
        $conteo = [];
        $resultado = [];

        foreach ($headers as $h) {
            $h = trim($h);
            $h = ($h === '' || is_numeric($h)) ? 'columna' : $h;
            $h = preg_replace('/\s+/', ' ', $h);

            if (isset($conteo[$h])) {
                $conteo[$h]++;
                $resultado[] = "{$h}_{$conteo[$h]}";
            } else {
                $conteo[$h] = 1;
                $resultado[] = $h;
            }
        }

        return $resultado;
    }

    private function detectarPrimeraFilaConDatos($ws, int $highRow, int $highCol): ?int
    {
        for ($row = 1; $row <= min($highRow, 60); $row++) {
            if (!$this->filaEstaVacia($this->leerFila($ws, $row, $highCol))) {
                return $row;
            }
        }
        return null;
    }

    private function filaEstaVacia(array $fila): bool
    {
        foreach ($fila as $v) {
            if (trim((string)$v) !== '') return false;
        }
        return true;
    }

    private function esFilaBasura(array $fila): bool
    {
        $primera = '';

        foreach ($fila as $v) {
            $v = trim((string)$v);
            if ($v !== '') {
                $primera = $v;
                break;
            }
        }

        if ($primera === '') return false;

        return (
            strtoupper($primera) === 'TOTAL' ||
            str_starts_with($primera, '*') ||
            str_starts_with($primera, '=')
        );
    }

    private function colLetraAIndice(string $ref): int
    {
        // Limpiamos los números para quedarnos solo con las letras de la columna (ej: "AB12" -> "AB")
        preg_match('/^([A-Z]+)/i', $ref, $m);
        $letters = strtoupper($m[1] ?? 'A');

        $col = 0;
        for ($i = 0; $i < strlen($letters); $i++) {
            $col = $col * 26 + (ord($letters[$i]) - ord('A') + 1);
        }

        return $col;
    }
}