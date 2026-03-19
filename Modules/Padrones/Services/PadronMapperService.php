<?php

namespace Modules\Padrones\Services;

use Ramsey\Uuid\Uuid;

class PadronMapperService
{
    // ── Patrones para campos canónicos ────────────────────────────────────────

    private string $reClave = '/^(clee|curp|rfc|rfc_empresa|folio|folio_id|folio_ctrl|num_folio|
        folio_beneficiario|id|id_unico|id_beneficiario|id_registro|id_persona|clave|clave_unica|
        clave_elector|clave_interna|clave_padron|matricula|num_matricula|expediente|num_expediente|
        registro|num_registro|num_beneficiario|nss|imss|issste|cuenta|num_cuenta)$/xi';

    private string $reNombre = '/^(nombre_completo|nombre_y_apellidos|nombre_beneficiario|
        nombre_establecimiento|nombre_empresa|nombre_negocio|nom_estab|razon_social|razon|
        denominacion|beneficiario|cliente|usuario|titular|propietario|representante)$/xi';

    private string $reNombreSolo = '/^(nombre|nombres|primer_nombre|name)$/xi';
    private string $rePaterno    = '/^(paterno|apellido_paterno|apellido_1|ap_pat|primer_apellido|last_name)$/xi';
    private string $reMaterno    = '/^(materno|apellido_materno|apellido_2|ap_mat|segundo_apellido)$/xi';

    private string $reMunicipio = '/^(municipio|municipio_nombre|nom_mun|nombre_municipio|
        cve_mun|c_mnpio|cvemun)$/xi';

    private string $reCP = '/^(cod_postal|codigo_post|cp|c_p|codigo_postal|cod_post|
        zip_code|zip|postal_code|c\.p\.)$/xi';

    private string $reSeccion = '/^(seccion|seccion_electoral|num_seccion|cve_seccion)$/xi';

    private string $reLatitud  = '/^(latitud|lat|latitude|coord_lat|y_coord|coordenada_y|geo_lat)$/xi';
    private string $reLongitud = '/^(longitud|lon|lng|longitude|coord_lon|x_coord|coordenada_x|geo_lon)$/xi';

    /**
     * Campos que se guardan en datos_generales pero NO como campos fijos.
     * Para padrones electorales: partidos, coaliciones, estadísticas.
     * Se guardan limpios, sin convertirse en campos estructurados.
     */
    private array $camposElectorales = [
        'pan', 'pri', 'prd', 'pt', 'pvem', 'mc', 'morena', 'naem', 'pes', 'rsp', 'fxm',
        'votos', 'votos_validos', 'votos_nulos', 'votos_no_registrados', 'total_votos',
        'lista_nominal', 'total_de_secciones', 'total_de_casillas',
        'participacion_ciudadana', 'participacion',
        'siglas', 'votacion', 'porcentaje', 'porcentual',
        'margen_de_victoria', 'margen',
        'ruta_acta', 'ruta acta',
    ];

    // Columnas completamente inútiles que se descartan (no van ni al JSON)
    private array $camposDescartables = [
        'columna', 'column', 'sin_nombre', 'unnamed', 'unnamed:',
        'total', // solo si es un campo de totales genérico sin contexto
    ];

    public function mapear(array $fila, string $uuidCatalogo): array
    {
        $registro = [
            'id'                 => Uuid::uuid7()->toString(),
            'catalogo_padron_id' => $uuidCatalogo,
            'clave_unica'        => null,
            'nombre_completo'    => 'SIN NOMBRE',
            'municipio'          => null,
            'codigo_postal'      => null,
            'seccion'            => null,
            'latitud'            => null,
            'longitud'           => null,
            'estatus_duplicidad' => 'limpio',
            'created_at'         => date('Y-m-d H:i:s'),
        ];

        $extra          = [];
        $clee           = null;
        $nom_estab      = null;
        $partesNombre   = ['n' => '', 'p' => '', 'm' => ''];
        $prioridadClave = null;

        foreach ($fila as $col => $valor) {
            // ── Normalizar encoding ────────────────────────────────────────────
            $colUtf8   = mb_convert_encoding((string)$col,   'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
            $valorUtf8 = mb_convert_encoding((string)$valor, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');

            $valorLimpio = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $valorUtf8));
            $colLimpia   = mb_strtolower(trim(str_replace("\xEF\xBB\xBF", '', $colUtf8)));

            // Normalizar espacios múltiples en el nombre de columna
            $colLimpia = preg_replace('/\s+/', ' ', $colLimpia);
            // Versión con guiones bajos (para matching)
            $colSnake  = str_replace([' ', '-'], '_', $colLimpia);

            // ── Descartar columnas sin nombre real ─────────────────────────────
            if ($colLimpia === '' || preg_match('/^columna_?\d*$/', $colSnake)) {
                // Solo guardar en extra si tiene valor (puede ser útil)
                if ($valorLimpio !== '') {
                    $extra["col_{$colLimpia}"] = $valorLimpio;
                }
                continue;
            }

            // ── Mapeo de campos estructurados ──────────────────────────────────

            if ($colSnake === 'clee') {
                $clee = mb_substr($valorLimpio, 0, 100);
                continue;
            }

            if ($colSnake === 'nom_estab') {
                $nom_estab = mb_substr($valorLimpio, 0, 255);
                continue;
            }

            // ID Municipio (campo especial para resultados electorales)
            if (in_array($colSnake, ['id_municipio', 'id municipio'], true)) {
                if ($valorLimpio !== '') $extra[$colUtf8] = $valorLimpio;
                continue;
            }

            if ($valorLimpio !== '') {
                if (preg_match($this->reClave, $colSnake)) {
                    if ($prioridadClave === null) $prioridadClave = mb_substr($valorLimpio, 0, 100);
                    continue;
                }

                if (preg_match($this->reNombre, $colSnake)) {
                    if ($registro['nombre_completo'] === 'SIN NOMBRE' && $nom_estab === null) {
                        $registro['nombre_completo'] = mb_substr($valorLimpio, 0, 255);
                    }
                    continue;
                }

                if (preg_match($this->reNombreSolo, $colSnake)) {
                    $partesNombre['n'] = $valorLimpio;
                    continue;
                }

                if (preg_match($this->rePaterno, $colSnake)) {
                    $partesNombre['p'] = $valorLimpio;
                    continue;
                }

                if (preg_match($this->reMaterno, $colSnake)) {
                    $partesNombre['m'] = $valorLimpio;
                    continue;
                }

                if (preg_match($this->reMunicipio, $colSnake)) {
                    $registro['municipio'] = mb_substr($valorLimpio, 0, 255);
                    continue;
                }

                if (preg_match($this->reCP, $colSnake)) {
                    $registro['codigo_postal'] = mb_substr($valorLimpio, 0, 10);
                    continue;
                }

                if (preg_match($this->reSeccion, $colSnake)) {
                    $registro['seccion'] = mb_substr($valorLimpio, 0, 255);
                    continue;
                }

                if (preg_match($this->reLatitud, $colSnake)) {
                    $val = (float)str_replace(',', '.', $valorLimpio);
                    $registro['latitud'] = ($val != 0) ? $val : null;
                    continue;
                }

                if (preg_match($this->reLongitud, $colSnake)) {
                    $val = (float)str_replace(',', '.', $valorLimpio);
                    $registro['longitud'] = ($val != 0) ? $val : null;
                    continue;
                }
            }

            // ── Todo lo demás va al JSON ───────────────────────────────────────
            // Incluye campos electorales, estadísticos, URLs, etc.
            // Solo si tiene valor real
            if ($valorLimpio !== '') {
                // Usar el nombre original (con espacios) como clave del JSON para legibilidad
                $claveJson = trim($colUtf8);
                $extra[$claveJson] = $valorLimpio;
            }
        }

        // ── Cierre: nombre y clave ─────────────────────────────────────────────

        if ($clee !== null) {
            $registro['clave_unica'] = $clee;
        } elseif ($prioridadClave !== null) {
            $registro['clave_unica'] = $prioridadClave;
        } else {
            $registro['clave_unica']        = md5($uuidCatalogo . json_encode($extra));
            $registro['estatus_duplicidad'] = 'generado_por_sistema';
        }

        if ($nom_estab !== null) {
            $registro['nombre_completo'] = $nom_estab;
        } elseif (
            $registro['nombre_completo'] === 'SIN NOMBRE'
            && ($partesNombre['n'] !== '' || $partesNombre['p'] !== '')
        ) {
            $registro['nombre_completo'] = trim(
                "{$partesNombre['p']} {$partesNombre['m']} {$partesNombre['n']}"
            );
        }

        // Para padrones electorales (sin nombre individual), usar municipio como identificador
        if ($registro['nombre_completo'] === 'SIN NOMBRE' && !empty($registro['municipio'])) {
            $registro['nombre_completo'] = $registro['municipio'];
        }

        $registro['datos_generales'] = !empty($extra)
            ? json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
            : null;

        return $registro;
    }
}