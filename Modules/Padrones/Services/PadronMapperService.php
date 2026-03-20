<?php

namespace Modules\Padrones\Services;

use Ramsey\Uuid\Uuid;

class PadronMapperService
{
    // =========================
    // CAMPOS FIJOS
    // =========================
    private array $camposFijos = [
        'clave_unica',
        'nombre_completo',
        'municipio',
        'codigo_postal',
        'seccion',
        'latitud',
        'longitud'
    ];

    // =========================
    // REGEX AUTO-DETECCIÓN
    // =========================
    private string $reClave = '/^(clee|curp|rfc|folio|id|clave|matricula|expediente|nss)$/xi';
    private string $reNombre = '/^(nombre_completo|nombre|nombres|razon_social|beneficiario|titular)$/xi';
    private string $rePaterno = '/^(apellido_paterno|paterno|ap_pat)$/xi';
    private string $reMaterno = '/^(apellido_materno|materno|ap_mat)$/xi';
    private string $reMunicipio = '/^(municipio|nom_mun)$/xi';
    private string $reCP = '/^(cp|codigo_postal|c_p)$/xi';
    private string $reSeccion = '/^(seccion)$/xi';
    private string $reLat = '/^(latitud|lat)$/xi';
    private string $reLng = '/^(longitud|lon|lng)$/xi';

    private array $basura = ['columna', 'unnamed', 'total'];

    // =========================================================
    // AUTO MAPPING (INTELIGENTE)
    // =========================================================
    public function mapear(array $fila, string $uuid): array
    {
        $registro = $this->base($uuid);

        $extra = [];
        $partes = ['n'=>'','p'=>'','m'=>''];
        $claveDetectada = null;

        foreach ($fila as $col => $val) {

            $col = $this->normalizar($col);
            $val = $this->limpiarValor($val);

            if ($val === '' || $this->esBasura($col)) continue;

            $esFijo = false;

            if (preg_match($this->reClave, $col)) {
                if (!$claveDetectada) $claveDetectada = $val;
                $esFijo = true;

            } elseif (preg_match($this->reNombre, $col)) {
                if ($registro['nombre_completo'] === 'SIN NOMBRE') {
                    $registro['nombre_completo'] = $val;
                }
                $esFijo = true;

            } elseif (preg_match($this->rePaterno, $col)) {
                $partes['p'] = $val; $esFijo = true;

            } elseif (preg_match($this->reMaterno, $col)) {
                $partes['m'] = $val; $esFijo = true;

            } elseif (preg_match($this->reMunicipio, $col)) {
                $registro['municipio'] = $val; $esFijo = true;

            } elseif (preg_match($this->reCP, $col)) {
                $registro['codigo_postal'] = $val; $esFijo = true;

            } elseif (preg_match($this->reSeccion, $col)) {
                $registro['seccion'] = $val; $esFijo = true;

            } elseif (preg_match($this->reLat, $col)) {
                $registro['latitud'] = $this->toFloat($val); $esFijo = true;

            } elseif (preg_match($this->reLng, $col)) {
                $registro['longitud'] = $this->toFloat($val); $esFijo = true;
            }

            if (!$esFijo) {
                $extra[$col] = $val;
            }
        }

        // Clave única
        if ($claveDetectada) {
            $registro['clave_unica'] = $claveDetectada;
        } else {
            ksort($extra);
            $registro['clave_unica'] = md5($uuid . json_encode($extra));
            $registro['estatus_duplicidad'] = 'generado_por_sistema';
        }

        // Nombre compuesto
        if ($registro['nombre_completo'] === 'SIN NOMBRE') {
            $nombre = trim("{$partes['p']} {$partes['m']} {$partes['n']}");
            if ($nombre !== '') {
                $registro['nombre_completo'] = $nombre;
            } elseif (!empty($registro['municipio'])) {
                $registro['nombre_completo'] = $registro['municipio'];
            }
        }

        $registro['datos_generales'] = $this->json($extra);

        return $registro;
    }

    // =========================================================
    // MANUAL MAPPING (UI)
    // =========================================================
    public function mapearManual(array $fila, string $uuid, array $config): array
    {
        $registro = $this->base($uuid);
        $extra = [];

        foreach ($fila as $col => $val) {

            $val = $this->limpiarValor($val);
            $destino = $config[$col] ?? null;

            if ($val === '' || !$destino) continue;

            if (in_array($destino, $this->camposFijos)) {

                if ($destino === 'latitud' || $destino === 'longitud') {
                    $registro[$destino] = $this->toFloat($val);
                } else {
                    $registro[$destino] = mb_substr($val, 0, 255);
                }

            } else {
                $extra[$destino] = $val;
            }
        }

        if (empty($registro['clave_unica'])) {
            ksort($extra);
            $registro['clave_unica'] = md5($uuid . json_encode($extra));
            $registro['estatus_duplicidad'] = 'generado_por_sistema';
        }

        $registro['datos_generales'] = $this->json($extra);

        return $registro;
    }

    // =========================================================
    // HELPERS
    // =========================================================

    private function base(string $uuid): array
    {
        return [
            'id' => Uuid::uuid7()->toString(),
            'catalogo_padron_id' => $uuid,
            'clave_unica' => null,
            'nombre_completo' => 'SIN NOMBRE',
            'municipio' => null,
            'codigo_postal' => null,
            'seccion' => null,
            'latitud' => null,
            'longitud' => null,
            'estatus_duplicidad' => 'limpio',
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    private function limpiarValor($v): string
    {
        $v = mb_convert_encoding((string)$v, 'UTF-8', 'UTF-8, ISO-8859-1');
        return trim(preg_replace('/[\x00-\x1F\x7F]/', '', $v));
    }

    private function normalizar($v): string
    {
        $v = mb_strtolower(trim((string)$v));
        return str_replace([' ', '-'], '_', $v);
    }

    private function esBasura(string $col): bool
    {
        foreach ($this->basura as $b) {
            if (str_contains($col, $b)) return true;
        }
        return false;
    }

    private function toFloat($v): ?float
    {
        $v = (float)str_replace(',', '.', $v);
        return ($v != 0) ? $v : null;
    }

    private function json(array $data): ?string
    {
        return !empty($data)
            ? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
            : null;
    }
}