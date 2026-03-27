<?php

namespace Modules\Padrones\Services;

use Ramsey\Uuid\Uuid;

class PadronMapperService
{
    // =========================
    // CAMPOS FIJOS Y LÍMITES
    // =========================
    // Definimos los límites exactos de la BD para evitar "Data too long for column"
    private array $limites = [
        'clave_unica'     => 100,
        'nombre_completo' => 255,
        'municipio'       => 255,
        'codigo_postal'   => 10,
        'seccion'         => 255
    ];

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
                $partes['n'] = $val; // <-- CORREGIDO: Guarda la parte del nombre
                $esFijo = true;

            } elseif (preg_match($this->rePaterno, $col)) {
                $partes['p'] = $val; $esFijo = true;

            } elseif (preg_match($this->reMaterno, $col)) {
                $partes['m'] = $val; $esFijo = true;

            } elseif (preg_match($this->reMunicipio, $col)) {
                $registro['municipio'] = $val; $esFijo = true;

            } elseif (preg_match($this->reCP, $col)) {
                $registro['codigo_postal'] = preg_replace('/[^0-9A-Za-z]/', '', $val); // <-- Limpieza de CP
                $esFijo = true;

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

        // Nombre compuesto (ahora funciona perfecto)
        $nombre = trim(preg_replace('/\s+/', ' ', "{$partes['n']} {$partes['p']} {$partes['m']}"));
        if ($nombre !== '') {
            $registro['nombre_completo'] = $nombre;
        } elseif (!empty($registro['municipio']) && $registro['nombre_completo'] === 'SIN NOMBRE') {
            $registro['nombre_completo'] = 'REGISTRO ' . mb_strtoupper($registro['municipio']);
        }

        // Clave única más robusta (incluye nombre para evitar colisiones)
        if ($claveDetectada) {
            $registro['clave_unica'] = $claveDetectada;
        } else {
            ksort($extra);
            $seed = $uuid . $registro['nombre_completo'] . json_encode($extra);
            $registro['clave_unica'] = md5($seed);
            $registro['estatus_duplicidad'] = 'generado_por_sistema';
        }

        $registro['datos_generales'] = $this->json($extra);
        
        // Truncar para evitar crasheos en BD
        $this->truncarLimitesSeguros($registro);

        return $registro;
    }

    // =========================================================
    // MANUAL MAPPING (UI)
    // =========================================================
    public function mapearManual(array $fila, string $uuid, array $config): array
    {
        $registro = $this->base($uuid);
        $extra = [];
        $claveDetectada = null;

        foreach ($fila as $col => $val) {

            $val = $this->limpiarValor($val);
            $destino = $config[$col] ?? null;

            if ($val === '' || !$destino) continue;

            if (in_array($destino, $this->camposFijos)) {

                if ($destino === 'latitud' || $destino === 'longitud') {
                    $registro[$destino] = $this->toFloat($val);
                } elseif ($destino === 'codigo_postal') {
                    $registro[$destino] = preg_replace('/[^0-9A-Za-z]/', '', $val);
                } else {
                    $registro[$destino] = $val;
                    if ($destino === 'clave_unica') {
                        $claveDetectada = $val;
                    }
                }

            } else {
                $extra[$destino] = $val;
            }
        }

        if ($claveDetectada) {
            $registro['clave_unica'] = $claveDetectada;
        } elseif (empty($registro['clave_unica'])) {
            ksort($extra);
            $seed = $uuid . $registro['nombre_completo'] . json_encode($extra);
            $registro['clave_unica'] = md5($seed);
            $registro['estatus_duplicidad'] = 'generado_por_sistema';
        }

        $registro['datos_generales'] = $this->json($extra);
        
        // Truncar para evitar crasheos en BD
        $this->truncarLimitesSeguros($registro);

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

    private function truncarLimitesSeguros(array &$registro): void
    {
        foreach ($this->limites as $campo => $maxLength) {
            if (!empty($registro[$campo])) {
                $registro[$campo] = mb_substr((string)$registro[$campo], 0, $maxLength, 'UTF-8');
            }
        }
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
        // Limpiamos todo lo que no sea número, punto o signo negativo (vital para el índice espacial)
        $v = preg_replace('/[^0-9\.\-]/', '', str_replace(',', '.', (string)$v));
        if ($v === '' || $v === '.') return null;

        $floatVal = (float)$v;
        return ($floatVal != 0) ? $floatVal : null;
    }

    private function json(array $data): ?string
    {
        return !empty($data)
            ? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
            : null;
    }
}