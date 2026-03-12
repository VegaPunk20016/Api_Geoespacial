<?php

namespace Modules\Padrones\Services;

use Ramsey\Uuid\Uuid;

class PadronMapperService
{
    private string $reClave    = '/^(clee|curp|rfc|rfc_empresa|folio|folio_id|folio_ctrl|num_folio|folio_beneficiario|id|id_unico|id_beneficiario|id_registro|id_persona|clave|clave_unica|clave_elector|clave_interna|clave_padron|matricula|num_matricula|expediente|num_expediente|registro|num_registro|num_beneficiario|nss|imss|issste|cuenta|num_cuenta)$/i';
    private string $reNombre   = '/^(nombre_completo|nombre_y_apellidos|nombre_beneficiario|nombre_establecimiento|nombre_empresa|nombre_negocio|nom_estab|razon_social|razon|denominacion|beneficiario|cliente|usuario|titular|propietario|representante)$/i';
    private string $reNombreSolo = '/^(nombre|nombres|primer_nombre|name)$/i';
    private string $rePaterno  = '/^(paterno|apellido_paterno|apellido_1|ap_pat|primer_apellido|last_name)$/i';
    private string $reMaterno  = '/^(materno|apellido_materno|apellido_2|ap_mat|segundo_apellido)$/i';
    private string $reMunicipio= '/^(municipio|municipio_nombre|nom_mun|nombre_municipio|cve_mun|c_mnpio|cvemun|delegacion|alcaldia|ciudad|localidad|nom_loc|demarcacion)$/i';
    private string $reEstado   = '/^(estado|entidad|entidad_federativa|entidad_nombre|nom_ent|cve_ent|c_estado|cveent|estado_nombre|estado_republica|nombre_estado|id_estado)$/i';
    private string $reSeccion  = '/^(seccion|seccion_electoral|num_seccion|cve_seccion)$/i';
    private string $reLatitud  = '/^(latitud|lat|latitude|coord_lat|y_coord|coordenada_y|geo_lat)$/i';
    private string $reLongitud = '/^(longitud|lon|lng|longitude|coord_lon|x_coord|coordenada_x|geo_lon)$/i';

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
            'created_at'         => date('Y-m-d H:i:s'),
        ];

        $extra          = [];
        $clee           = null;
        $nom_estab      = null;
        $partesNombre   = ['n' => '', 'p' => '', 'm' => ''];
        $prioridadClave = null;

        foreach ($fila as $col => $valor) {

            $colUtf8     = mb_convert_encoding((string)$col,   'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
            $valorUtf8   = mb_convert_encoding((string)$valor, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
            $valorLimpio = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $valorUtf8));
            $colLimpia   = mb_strtolower(trim(str_replace("\xEF\xBB\xBF", '', $colUtf8)));

            // Siempre al JSON (excepto coordenadas)
            if (!preg_match($this->reLongitud, $colLimpia) && !preg_match($this->reLatitud, $colLimpia)) {
                $extra[$colUtf8] = $valorLimpio;
            }

            if ($valorLimpio === '') continue;

            // ── Explícitos con máxima prioridad ───────────────────────────
            if ($colLimpia === 'clee')    { $clee      = mb_substr($valorLimpio, 0, 100); continue; }
            if ($colLimpia === 'nom_estab') { $nom_estab = mb_substr($valorLimpio, 0, 255); continue; }
            if ($colLimpia === 'seccion') { $registro['seccion'] = mb_substr($valorLimpio, 0, 255); continue; }

            // ── Clave ─────────────────────────────────────────────────────
            if (preg_match($this->reClave, $colLimpia)) {
                if ($prioridadClave === null) $prioridadClave = mb_substr($valorLimpio, 0, 100);
                continue;
            }

            // ── Nombre completo ───────────────────────────────────────────
            if (preg_match($this->reNombre, $colLimpia)) {
                if ($registro['nombre_completo'] === 'SIN NOMBRE' && $nom_estab === null)
                    $registro['nombre_completo'] = mb_substr($valorLimpio, 0, 255);
                continue;
            }

            // ── Nombre fragmentado ────────────────────────────────────────
            if (preg_match($this->reNombreSolo, $colLimpia)) { $partesNombre['n'] = $valorLimpio; continue; }
            if (preg_match($this->rePaterno,    $colLimpia)) { $partesNombre['p'] = $valorLimpio; continue; }
            if (preg_match($this->reMaterno,    $colLimpia)) { $partesNombre['m'] = $valorLimpio; continue; }

            // ── Municipio ─────────────────────────────────────────────────
            if (preg_match($this->reMunicipio, $colLimpia)) {
                $registro['municipio'] = mb_substr($valorLimpio, 0, 255);
                continue;
            }

            // ── Sección (regex para variantes) ────────────────────────────
            if (preg_match($this->reSeccion, $colLimpia)) {
                $registro['seccion'] = mb_substr($valorLimpio, 0, 255);
                continue;
            }

            // ── Coordenadas ───────────────────────────────────────────────
            if (preg_match($this->reLatitud, $colLimpia)) {
                $val = (float) str_replace(',', '.', $valorLimpio);
                $registro['latitud'] = ($val != 0) ? $val : null;
                continue;
            }
            if (preg_match($this->reLongitud, $colLimpia)) {
                $val = (float) str_replace(',', '.', $valorLimpio);
                $registro['longitud'] = ($val != 0) ? $val : null;
                continue;
            }
        }

        // ── Cierre clave ──────────────────────────────────────────────────
        if ($clee !== null) {
            $registro['clave_unica'] = $clee;
        } elseif ($prioridadClave !== null) {
            $registro['clave_unica'] = $prioridadClave;
        } else {
            $registro['clave_unica']        = md5($uuidCatalogo . json_encode($extra));
            $registro['estatus_duplicidad'] = 'generado_por_sistema';
        }

        // ── Cierre nombre ─────────────────────────────────────────────────
        if ($nom_estab !== null) {
            $registro['nombre_completo'] = $nom_estab;
        } elseif ($registro['nombre_completo'] === 'SIN NOMBRE'
            && ($partesNombre['n'] !== '' || $partesNombre['p'] !== '')) {
            $registro['nombre_completo'] = trim("{$partesNombre['p']} {$partesNombre['m']} {$partesNombre['n']}");
        }

        // ── datos_generales ───────────────────────────────────────────────
        $registro['datos_generales'] = !empty($extra)
            ? json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
            : null;

        if ($registro['datos_generales'] === false) {
            $registro['datos_generales'] = json_encode(['error' => 'Codificación corrupta en fila']);
        }

        return $registro;
    }
}