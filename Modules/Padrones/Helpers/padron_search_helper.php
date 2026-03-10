<?php

if (!function_exists('padron_normalizar_texto')) {
    /**
     * Normaliza texto para búsquedas
     */
    function padron_normalizar_texto(string $texto): string
    {
        $texto = trim($texto);
        $texto = mb_strtolower($texto, 'UTF-8');

        $acentos = [
            'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u',
            'ä'=>'a','ë'=>'e','ï'=>'i','ö'=>'o','ü'=>'u',
            'ñ'=>'n'
        ];

        return strtr($texto, $acentos);
    }
}

if (!function_exists('padron_sanitizar_busqueda')) {
    /**
     * Sanitiza texto para consultas SQL seguras
     */
    function padron_sanitizar_busqueda(string $texto): string
    {
        $texto = padron_normalizar_texto($texto);

        // eliminar caracteres peligrosos
        $texto = preg_replace('/[^a-z0-9\s]/', '', $texto);

        return trim($texto);
    }
}

if (!function_exists('padron_detectar_tipo_busqueda')) {
    /**
     * Detecta si la búsqueda es por clave única / CLEE
     */
    function padron_detectar_tipo_busqueda(string $query): string
    {
        $query = trim($query);

        if (preg_match('/^[0-9]{10,}$/', $query)) {
            return 'clave_unica';
        }

        if (preg_match('/^[A-Z0-9]{8,}$/', strtoupper($query))) {
            return 'clee';
        }

        return 'nombre';
    }
}

if (!function_exists('padron_aplicar_filtro_busqueda')) {
    /**
     * Aplica filtros de búsqueda al Query Builder de CI4
     */
    function padron_aplicar_filtro_busqueda(
        \CodeIgniter\Database\BaseBuilder $builder,
        string $query
    ): \CodeIgniter\Database\BaseBuilder {

        $tipo = padron_detectar_tipo_busqueda($query);
        $query = padron_sanitizar_busqueda($query);

        switch ($tipo) {

            case 'clave_unica':
                $builder->groupStart()
                    ->like('clave_unica', $query)
                    ->orLike('clee', $query)
                ->groupEnd();
                break;

            case 'clee':
                $builder->groupStart()
                    ->like('clee', $query)
                    ->orLike('clave_unica', $query)
                ->groupEnd();
                break;

            case 'nombre':
            default:

                $builder->groupStart()
                    ->like('nom_estab', $query)
                    ->orLike('nombre_completo', $query)
                ->groupEnd();

                break;
        }

        return $builder;
    }
}

if (!function_exists('padron_columnas_prioritarias')) {
    /**
     * Orden de prioridad para columnas de búsqueda
     */
    function padron_columnas_prioritarias(): array
    {
        return [
            'nom_estab',
            'nombre_completo',
            'clee',
            'clave_unica'
        ];
    }
}

if (!function_exists('padron_preparar_resultado')) {
    /**
     * Formatea resultados para salida estándar
     */
    function padron_preparar_resultado(array $row): array
    {
        return [
            'id' => $row['id'] ?? null,
            'nombre' => $row['nom_estab'] ?? $row['nombre_completo'] ?? null,
            'clee' => $row['clee'] ?? null,
            'clave_unica' => $row['clave_unica'] ?? null,
        ];
    }
}