<?php

namespace Modules\Padrones\Services;

use Shuchkin\SimpleXLSX;
use RuntimeException;

class FileConverterService
{
    public function prepararCsv(string $rutaOriginal, string $extension): string
    {
        $extension   = strtolower($extension);
        $rutaDestino = $rutaOriginal . '_convertido.csv';

        switch ($extension) {
            case 'csv':
                return $rutaOriginal;
            case 'xlsx':
            case 'xls':
                return $this->convertirExcelACsv($rutaOriginal, $rutaDestino);
            case 'txt':
                return $this->convertirTxtACsv($rutaOriginal, $rutaDestino);
            default:
                throw new RuntimeException("Formato de archivo no soportado: {$extension}");
        }
    }

    private function convertirExcelACsv(string $rutaOriginal, string $rutaDestino): string
    {
        $xlsx = SimpleXLSX::parse($rutaOriginal);
        if (!$xlsx) {
            throw new RuntimeException('Error al leer el archivo Excel: ' . SimpleXLSX::parseError());
        }

        $todasLasFilas = $xlsx->rows();
        $maxColumnas   = $xlsx->dimension()[0] ?? 0;

        // Encontrar la primera fila que tenga suficientes celdas no vacías
        // para ser considerada la fila de headers (al menos 3 celdas con texto)
        $indiceInicio = 0;
        foreach ($todasLasFilas as $i => $fila) {
            $noVacias = count(array_filter((array)$fila, fn($v) => trim((string)$v) !== ''));
            if ($noVacias >= 3) {
                $indiceInicio = $i;
                break;
            }
        }

        $fp = fopen($rutaDestino, 'w');
        if (!$fp) {
            throw new RuntimeException("No se pudo crear el archivo CSV destino.");
        }

        foreach ($todasLasFilas as $i => $fila) {
            // Saltar filas anteriores al header detectado
            if ($i < $indiceInicio) continue;

            $fila = (array) $fila;

            // Normalizar número de columnas
            if ($maxColumnas > 0 && count($fila) < $maxColumnas) {
                $fila = array_pad($fila, $maxColumnas, '');
            }

            fputcsv($fp, $fila, ',', '"', '\\');
        }

        fclose($fp);
        return $rutaDestino;
    }

    private function convertirTxtACsv(string $rutaOriginal, string $rutaDestino): string
    {
        $input  = fopen($rutaOriginal, 'r');
        $output = fopen($rutaDestino, 'w');

        if (!$input || !$output) {
            throw new RuntimeException("No se pudo abrir el archivo TXT para conversión.");
        }

        $primeraLinea = fgets($input);
        $delimitador  = (strpos($primeraLinea, "\t") !== false) ? "\t" : '|';
        rewind($input);

        while (($linea = fgetcsv($input, 0, $delimitador)) !== false) {
            fputcsv($output, $linea, ',', '"', '\\');
        }

        fclose($input);
        fclose($output);

        return $rutaDestino;
    }
}