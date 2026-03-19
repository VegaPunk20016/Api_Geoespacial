<?php

namespace Modules\Padrones\Services;

use RuntimeException;

class FileConverterService
{
    /**
     * Prepara un CSV limpio a partir de CSV o XLSX.
     *
     * Para XLSX: detecta automáticamente:
     *   - Filas vacías al inicio (las salta)
     *   - Headers multi-nivel (fusiona super-header + sub-header)
     *   - Headers duplicados (añade sufijo)
     *   - Columnas sin nombre (genera "columna_N")
     */
    public function prepararCsv(string $rutaOriginal, string $extension): string
    {
        $ext = strtolower(trim($extension, '.'));

        if ($ext === 'csv' || $ext === 'txt') {
            return $rutaOriginal;
        }

        if (in_array($ext, ['xlsx', 'xls'], true)) {
            return $this->xlsxACsv($rutaOriginal);
        }

        throw new RuntimeException("Formato no soportado: {$extension}");
    }

    // =========================================================
    // XLSX → CSV  (motor propio con PhpSpreadsheet si existe,
    //               o con el lector nativo de ZIP/XML)
    // =========================================================

    private function xlsxACsv(string $ruta): string
    {
        // Intentar con PhpSpreadsheet si está disponible
        if (class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
            return $this->xlsxACsvConPhpSpreadsheet($ruta);
        }

        // Fallback: leer ZIP/XML directamente (sin dependencias)
        return $this->xlsxACsvNativo($ruta);
    }

    // ── PhpSpreadsheet (preferido) ────────────────────────────────────────────
    private function xlsxACsvConPhpSpreadsheet(string $ruta): string
    {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($ruta);
        $ws          = $spreadsheet->getActiveSheet();

        $highRow = $ws->getHighestRow();
        $highCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString(
            $ws->getHighestColumn()
        );

        // 1. Detectar primera fila con datos reales
        $primeraFilaDatos = $this->detectarPrimeraFilaConDatos($ws, $highRow, $highCol);
        if ($primeraFilaDatos === null) {
            throw new RuntimeException("El archivo XLSX no contiene datos.");
        }

        // 2. Detectar si hay super-headers en la fila anterior
        $filaHeaders    = $primeraFilaDatos;
        $filaSuperHeader = null;

        if ($primeraFilaDatos > 1) {
            $filaAnterior = $primeraFilaDatos - 1;
            $datosAnterior = $this->leerFila($ws, $filaAnterior, $highCol);
            $noVaciosAnterior = array_filter($datosAnterior, fn($v) => trim($v) !== '');

            // Si la fila anterior tiene datos pero MENOS columnas con valor
            // que la fila principal → es un super-header
            $datosActual   = $this->leerFila($ws, $primeraFilaDatos, $highCol);
            $noVaciosActual = array_filter($datosActual, fn($v) => trim($v) !== '');

            if (count($noVaciosAnterior) > 0 && count($noVaciosAnterior) < count($noVaciosActual)) {
                $filaSuperHeader = $filaAnterior;
            }
        }

        // 3. Construir headers fusionados
        $headers = $this->construirHeadersFusionados($ws, $filaSuperHeader, $filaHeaders, $highCol);

        // 4. Escribir CSV
        $destino = sys_get_temp_dir() . '/padron_' . uniqid() . '.csv';
        $handle  = fopen($destino, 'w');
        fputcsv($handle, $headers);

        for ($row = $filaHeaders + 1; $row <= $highRow; $row++) {
            $fila = $this->leerFila($ws, $row, $highCol);
            if ($this->filaEstaVacia($fila)) continue;
            fputcsv($handle, $fila);
        }

        fclose($handle);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $destino;
    }

    // ── Fallback nativo (sin dependencias) ────────────────────────────────────
    private function xlsxACsvNativo(string $ruta): string
    {
        // XLSX es un ZIP con XML adentro
        $zip = new \ZipArchive();
        if ($zip->open($ruta) !== true) {
            throw new RuntimeException("No se pudo abrir el archivo XLSX.");
        }

        // Leer strings compartidos
        $sharedStrings = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml) {
            $ss = new \SimpleXMLElement($ssXml);
            foreach ($ss->si as $si) {
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

        // Leer primera hoja
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if (!$sheetXml) {
            // Intentar con sheet2, sheet3...
            for ($i = 2; $i <= 5; $i++) {
                $sheetXml = $zip->getFromName("xl/worksheets/sheet{$i}.xml");
                if ($sheetXml) break;
            }
        }
        $zip->close();

        if (!$sheetXml) {
            throw new RuntimeException("No se encontró la hoja de cálculo en el XLSX.");
        }

        // Parsear XML de la hoja
        $xml   = new \SimpleXMLElement($sheetXml);
        $filas = [];

        foreach ($xml->sheetData->row as $row) {
            $rowIdx  = (int)$row['r'];
            $filaArr = [];
            $maxCol  = 0;

            foreach ($row->c as $cell) {
                $ref     = (string)$cell['r'];
                $colIdx  = $this->colLetraAIndice($ref) - 1;
                $tipo    = (string)$cell['t'];
                $valXml  = isset($cell->v) ? (string)$cell->v : '';

                if ($tipo === 's') {
                    $valor = $sharedStrings[(int)$valXml] ?? '';
                } elseif ($tipo === 'str' || $tipo === 'inlineStr') {
                    $valor = isset($cell->is->t) ? (string)$cell->is->t : $valXml;
                } else {
                    $valor = $valXml;
                }

                $filaArr[$colIdx] = $valor;
                if ($colIdx > $maxCol) $maxCol = $colIdx;
            }

            // Rellenar huecos con cadena vacía
            $filaCompleta = [];
            for ($c = 0; $c <= $maxCol; $c++) {
                $filaCompleta[] = $filaArr[$c] ?? '';
            }

            $filas[$rowIdx] = $filaCompleta;
        }

        if (empty($filas)) {
            throw new RuntimeException("El archivo XLSX está vacío.");
        }

        // Detectar y fusionar headers
        $allRows  = $filas;
        $rowNums  = array_keys($allRows);
        sort($rowNums);

        // Buscar primera fila no vacía
        $primeraFila = null;
        foreach ($rowNums as $rn) {
            if (!$this->filaEstaVacia($allRows[$rn])) {
                $primeraFila = $rn;
                break;
            }
        }

        if ($primeraFila === null) {
            throw new RuntimeException("El XLSX no contiene datos.");
        }

        // Detectar super-header
        $filaHeaders    = $primeraFila;
        $filaSuperHeader = null;

        // Buscar fila anterior con datos (puede no ser $primeraFila-1 si hay gaps)
        $idxPrimera = array_search($primeraFila, $rowNums);
        if ($idxPrimera > 0) {
            $filaAnteriorNum = $rowNums[$idxPrimera - 1];
            $anterior  = $allRows[$filaAnteriorNum];
            $actual    = $allRows[$primeraFila];
            $cntAnt    = count(array_filter($anterior, fn($v) => trim($v) !== ''));
            $cntAct    = count(array_filter($actual,   fn($v) => trim($v) !== ''));

            if ($cntAnt > 0 && $cntAnt < $cntAct) {
                $filaSuperHeader = $filaAnteriorNum;
            }
        }

        $headers = $this->construirHeadersDesdeArrays(
            $filaSuperHeader !== null ? $allRows[$filaSuperHeader] : null,
            $allRows[$filaHeaders]
        );

        // Escribir CSV
        $destino = sys_get_temp_dir() . '/padron_' . uniqid() . '.csv';
        $handle  = fopen($destino, 'w');
        fputcsv($handle, $headers);

        foreach ($rowNums as $rn) {
            if ($rn <= $filaHeaders) continue;
            $fila = $allRows[$rn];
            if ($this->filaEstaVacia($fila)) continue;

            // Alinear longitud con headers
            while (count($fila) < count($headers)) $fila[] = '';
            fputcsv($handle, array_slice($fila, 0, count($headers)));
        }

        fclose($handle);
        return $destino;
    }

    // =========================================================
    // HELPERS
    // =========================================================

    /**
     * Fusiona super-header + sub-header para construir nombres de columna únicos.
     * 
     * Lógica:
     * - Si la columna tiene sub-header → usar sub-header
     * - Si la columna NO tiene sub-header pero tiene super-header → usar super-header
     * - Si hay duplicados → añadir sufijo incremental
     * - Columnas completamente vacías → "columna_N"
     *
     * Para sub-headers duplicados bajo distintos super-headers:
     *   "Siglas" bajo "CANDIDATO GANADOR"   → "CANDIDATO GANADOR - Siglas"
     *   "Siglas" bajo "CANDIDATO 2DO LUGAR" → "CANDIDATO 2DO LUGAR - Siglas"
     */
    private function construirHeadersFusionados(
        $ws,
        ?int $filaSuperHeader,
        int $filaHeaders,
        int $maxCol
    ): array {
        $superActual = null;
        $brutos      = [];

        for ($col = 1; $col <= $maxCol; $col++) {
            $superVal = $filaSuperHeader
                ? trim((string)$ws->getCellByColumnAndRow($col, $filaSuperHeader)->getValue())
                : '';
            $subVal   = trim((string)$ws->getCellByColumnAndRow($col, $filaHeaders)->getValue());

            // Actualizar super-header activo cuando encontramos uno nuevo
            if ($superVal !== '') $superActual = $superVal;

            if ($subVal !== '') {
                // Sub-header existe: si hay super-header activo Y es diferente al sub, combinar
                if ($superActual && $superVal === '' && $subVal !== $superActual) {
                    $brutos[] = "{$superActual} - {$subVal}";
                } else {
                    $brutos[] = $subVal;
                    if ($superVal) $superActual = null; // nueva sección
                }
            } else {
                // Sin sub-header: usar super-header o placeholder
                $brutos[] = $superActual ?? "columna_{$col}";
                $superActual = null;
            }
        }

        return $this->hacerHeadersUnicos($brutos);
    }

    private function construirHeadersDesdeArrays(?array $superRow, array $subRow): array
    {
        $maxCol      = max(count($superRow ?? []), count($subRow));
        $superActual = null;
        $brutos      = [];

        for ($i = 0; $i < $maxCol; $i++) {
            $superVal = $superRow ? trim((string)($superRow[$i] ?? '')) : '';
            $subVal   = trim((string)($subRow[$i] ?? ''));

            if ($superVal !== '') $superActual = $superVal;

            if ($subVal !== '') {
                if ($superActual && $superVal === '' && $subVal !== $superActual) {
                    $brutos[] = "{$superActual} - {$subVal}";
                } else {
                    $brutos[] = $subVal;
                    if ($superVal) $superActual = null;
                }
            } else {
                $brutos[] = $superActual ?? "columna_" . ($i + 1);
                $superActual = null;
            }
        }

        return $this->hacerHeadersUnicos($brutos);
    }

    private function hacerHeadersUnicos(array $headers): array
    {
        $conteo = [];
        $unicos = [];
        foreach ($headers as $h) {
            $h = (trim($h) === '' || is_numeric($h)) ? 'columna' : trim($h);
            // Normalizar espacios múltiples dentro del nombre
            $h = preg_replace('/\s+/', ' ', $h);
            if (isset($conteo[$h])) {
                $conteo[$h]++;
                $unicos[] = $h . '_' . $conteo[$h];
            } else {
                $conteo[$h] = 1;
                $unicos[] = $h;
            }
        }
        return $unicos;
    }

    private function detectarPrimeraFilaConDatos($ws, int $highRow, int $highCol): ?int
    {
        for ($row = 1; $row <= min($highRow, 60); $row++) {
            $fila = $this->leerFila($ws, $row, $highCol);
            if (!$this->filaEstaVacia($fila)) return $row;
        }
        return null;
    }

    private function leerFila($ws, int $row, int $highCol): array
    {
        $fila = [];
        for ($col = 1; $col <= $highCol; $col++) {
            $cell  = $ws->getCellByColumnAndRow($col, $row);
            $valor = $cell->getFormattedValue();
            $fila[] = trim((string)$valor);
        }
        return $fila;
    }

    private function filaEstaVacia(array $fila): bool
    {
        foreach ($fila as $v) {
            if (trim((string)$v) !== '') return false;
        }
        return true;
    }

    private function colLetraAIndice(string $cellRef): int
    {
        // Extrae la parte de letras de una referencia como "AB12" → "AB" → índice
        preg_match('/^([A-Z]+)/i', $cellRef, $m);
        $letters = strtoupper($m[1] ?? 'A');
        $col = 0;
        $len = strlen($letters);
        for ($i = 0; $i < $len; $i++) {
            $col = $col * 26 + (ord($letters[$i]) - ord('A') + 1);
        }
        return $col;
    }
}