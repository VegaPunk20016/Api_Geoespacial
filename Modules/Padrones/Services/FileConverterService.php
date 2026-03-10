<?php

namespace Modules\Padrones\Services;

use Shuchkin\SimpleXLSX;
use RuntimeException;

class FileConverterService
{
    public function prepararCsv(string $rutaOriginal, string $extension): string
    {
        $extension = strtolower($extension);
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
        if ($xlsx = SimpleXLSX::parse($rutaOriginal)) {
            $fp = fopen($rutaDestino, 'w');
            
            // Obtenemos el número máximo de columnas detectadas para normalizar el CSV
            $dimensiones = $xlsx->dimension();
            $maxColumnas = $dimensiones[0];

            foreach ($xlsx->rows() as $fields) {
                // 1. Convertimos a array y forzamos que tenga el tamaño correcto
                $fila = (array) $fields;
                
                // Si la fila viene corta (ej. solo 3 columnas), la rellenamos con vacíos
                // Esto evita el error "Undefined array key 4" en el importador
                if (count($fila) < $maxColumnas) {
                    $fila = array_pad($fila, $maxColumnas, '');
                }

                // 2. fputcsv con los 5 parámetros requeridos para evitar el WARNING de PHP 8.4
                // Parámetros: recurso, datos, delimitador, cerramiento, escape
                fputcsv($fp, $fila, ',', '"', '\\');
            }
            
            fclose($fp);
            return $rutaDestino;
        } else {
            throw new RuntimeException('Error al leer el archivo Excel: ' . SimpleXLSX::parseError());
        }
    }

    private function convertirTxtACsv(string $rutaOriginal, string $rutaDestino): string
    {
        $input = fopen($rutaOriginal, 'r');
        $output = fopen($rutaDestino, 'w');

        if (!$input || !$output) {
            throw new RuntimeException("No se pudo abrir el archivo TXT para conversión.");
        }

        $primeraLinea = fgets($input);
        // Detectar si es TAB o PIPE
        $delimitador = (strpos($primeraLinea, "\t") !== false) ? "\t" : '|';

        rewind($input);

        while (($linea = fgetcsv($input, 0, $delimitador)) !== false) {
            fputcsv($output, $linea, ',', '"', '\\');
        }

        fclose($input);
        fclose($output);

        return $rutaDestino;
    }
}