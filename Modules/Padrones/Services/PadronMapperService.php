<?php

namespace Modules\Padrones\Services;

use Ramsey\Uuid\Uuid;

class PadronMapperService
{
    public function mapear(array $fila, string $uuidCatalogo): array
    {
        $registro = [
            'id'                 => Uuid::uuid7()->toString(),
            'catalogo_padron_id' => $uuidCatalogo,
            'clave_unica'        => null,
            'nombre_completo'    => 'SIN NOMBRE',
            'municipio'          => null, 
            'seccion'            => null,
            'latitud'            => null,
            'longitud'           => null,
            'estatus_duplicidad' => 'limpio',
            'created_at'         => date('Y-m-d H:i:s')
        ];

        $extra = [];
        
        // Variables para reconstruir nombres partidos
        $partesNombre = ['n' => '', 'p' => '', 'm' => ''];
        $prioridadClave = null;

        foreach ($fila as $col => $valor) {
            // Limpieza y Encoding
            $colUtf8 = mb_convert_encoding($col, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
            $valorUtf8 = mb_convert_encoding($valor, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
            $valorLimpio = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $valorUtf8));
            
            if ($valorLimpio === '') continue;

            $colLimpia = mb_strtolower(trim($colUtf8));

            // 1. DETECCIÓN DE MUNICIPIO (Campo Geográfico)
            // Solo entra aquí si la columna se llama explícitamente municipio o cve_mun
            if (preg_match('/^(municipio|mun|delegacion|alcaldia|nom_mun|cve_mun)$/i', $colLimpia)) {
                $registro['municipio'] = mb_substr($valorLimpio, 0, 255);
            }

            // 2. DETECCIÓN DE NOMBRE COMPLETO (Campo Sujeto)
            // Eliminamos "municipio" de este regex para evitar colisiones
            elseif (preg_match('/^(nombre_completo|nombre_y_apellidos|razon_social|nom_estab|beneficiario)$/i', $colLimpia)) {
                $registro['nombre_completo'] = mb_substr($valorLimpio, 0, 255);
            }

            // 3. NOMBRE FRAGMENTADO (Construcción)
            elseif (preg_match('/(^nombre$|^nombres$)/i', $colLimpia)) {
                $partesNombre['n'] = $valorLimpio;
            }
            elseif (preg_match('/(paterno|apellido_1|ap_pat)/i', $colLimpia)) {
                $partesNombre['p'] = $valorLimpio;
            }
            elseif (preg_match('/(materno|apellido_2|ap_mat)/i', $colLimpia)) {
                $partesNombre['m'] = $valorLimpio;
            }

            // 4. SECCIÓN Y COORDENADAS
            elseif (preg_match('/(seccion|sec|seccion_electoral)/i', $colLimpia)) {
                $registro['seccion'] = mb_substr($valorLimpio, 0, 255);
            }
            elseif (preg_match('/^(lat|latitud|y_coord)$/i', $colLimpia)) {
                $val = (float)str_replace(',', '.', $valorLimpio);
                $registro['latitud'] = ($val != 0) ? $val : null;
            } 
            elseif (preg_match('/^(lon|lng|longitud|x_coord)$/i', $colLimpia)) {
                $val = (float)str_replace(',', '.', $valorLimpio);
                $registro['longitud'] = ($val != 0) ? $val : null;
            }

            // 5. CLAVES ÚNICAS
            elseif (preg_match('/^(clee|curp|rfc|folio|id_unico|clave_elector)$/i', $colLimpia)) {
                $prioridadClave = mb_substr($valorLimpio, 0, 100);
            }

            // Guardar el resto en el JSON de extras
            if (!preg_match('/^(latitud|longitud|lat|lng|lon)$/i', $colLimpia)) {
                $extra[$colUtf8] = $valorLimpio;
            }
        }

        // --- LÓGICA DE CIERRE ---

        // Unir nombre si venía partido y no encontramos uno ya completo
        if ($registro['nombre_completo'] === 'SIN NOMBRE' && ($partesNombre['n'] !== '' || $partesNombre['p'] !== '')) {
            $registro['nombre_completo'] = trim("{$partesNombre['p']} {$partesNombre['m']} {$partesNombre['n']}");
        }

        // Asignación de clave (Física o Hash)
        if ($prioridadClave) {
            $registro['clave_unica'] = $prioridadClave;
        } else {
            $registro['clave_unica'] = md5($uuidCatalogo . json_encode($extra));
            $registro['estatus_duplicidad'] = 'generado_por_sistema';
        }

        $registro['datos_generales'] = json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return $registro;
    }
}