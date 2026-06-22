<?php

namespace App\Support;

/**
 * Gera o texto em linguagem natural (PT-BR) de cada atividade de tarefa, em
 * primeira pessoa, com pequenas variações para soar natural. Os textos podem
 * conter <b> (servidor confia no próprio markup); o título da tarefa é sempre
 * escapado, pois o histórico é renderizado como HTML no front.
 */
class ActivityNarrator
{
    /** @param array<int,string> $variants */
    private static function pick(array $variants): string
    {
        return $variants[array_rand($variants)];
    }

    private static function q(string $title): string
    {
        $t = trim($title) !== '' ? $title : 'sem título';

        return '«<b>' . e($t) . '</b>»';
    }

    public static function created(string $title): string
    {
        return self::pick([
            'criou a tarefa ' . self::q($title),
            'adicionou a tarefa ' . self::q($title),
            'registrou a nova tarefa ' . self::q($title),
        ]);
    }

    public static function started(string $title): string
    {
        return self::pick([
            'começou a trabalhar em ' . self::q($title),
            'iniciou o trabalho em ' . self::q($title),
            'colocou ' . self::q($title) . ' em andamento',
        ]);
    }

    public static function completed(string $title, ?int $minutes): string
    {
        $d = self::duration($minutes);
        $base = self::pick([
            'concluiu ' . self::q($title),
            'finalizou ' . self::q($title),
            'deu como concluída a tarefa ' . self::q($title),
        ]);

        return $base . ($d ? ' — levou <b>' . $d . '</b>' : '');
    }

    public static function reopened(string $title): string
    {
        return 'reabriu ' . self::q($title);
    }

    public static function cancelled(string $title): string
    {
        return 'cancelou ' . self::q($title);
    }

    public static function statusChanged(string $label): string
    {
        return 'moveu para <b>' . e($label) . '</b>';
    }

    public static function priorityChanged(string $label): string
    {
        return 'alterou a prioridade para <b>' . e($label) . '</b>';
    }

    public static function dueChanged(string $label): string
    {
        return 'alterou o prazo para <b>' . e($label) . '</b>';
    }

    public static function descriptionEdited(): string
    {
        return 'editou a descrição';
    }

    public static function projectChanged(string $name): string
    {
        return 'moveu para o projeto <b>' . e($name) . '</b>';
    }

    public static function archived(string $title): string
    {
        return 'arquivou ' . self::q($title);
    }

    public static function restored(string $title): string
    {
        return 'restaurou ' . self::q($title);
    }

    public static function recurred(string $title): string
    {
        return self::pick([
            'recriou ' . self::q($title) . ' (recorrência)',
            'gerou a próxima ocorrência de ' . self::q($title),
        ]);
    }

    /** Sufixo anexado à 1ª entrada da tarefa quando ela é concluída (tempo total trabalhado). */
    public static function completionSuffix(?int $minutes): string
    {
        $d = self::duration($minutes);

        return $d ? ' · concluída em <b>' . $d . '</b>' : '';
    }

    /** Formata minutos em "Xh Ymin" / "Ymin". Vazio se nulo/zero. */
    public static function duration(?int $minutes): string
    {
        if ($minutes === null || $minutes <= 0) {
            return '';
        }
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        if ($h && $m) {
            return "{$h}h {$m}min";
        }

        return $h ? "{$h}h" : "{$m}min";
    }
}
