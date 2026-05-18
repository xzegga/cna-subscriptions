<?php
/**
 * Scheduler - Algoritmo de cálculo de fechas de entrega
 * Implementa la lógica de entregas los Jueves con corte los Miércoles
 *
 * @package CNA_Subscriptions
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class CNA_Scheduler {

    /**
     * Calcula las fechas de entrega según las reglas del negocio
     *
     * Reglas:
     * - Día de Entrega: Configurable por producto (por defecto Jueves = 4)
     * - Día de Corte: Configurable por producto (por defecto Miércoles = 2)
     * - Si compra antes del día de corte: Primera entrega es este día de entrega
     * - Si compra en o después del día de corte: Primera entrega es el día de entrega de la próxima semana
     * - Fechas subsiguientes se calculan sumando la frecuencia en semanas
     *
     * @param string|DateTime $start_date Fecha de inicio (puede ser 'now' o DateTime)
     * @param int $quantity Cantidad de entregas
     * @param int $frequency_weeks Frecuencia en semanas (ej: 1, 2, 4)
     * @param int $delivery_day Día de entrega (0=Domingo, 1=Lunes, ..., 6=Sábado). Por defecto 4 (Jueves)
     * @param int $order_cutoff Día de corte (0=Domingo, 1=Lunes, ..., 6=Sábado). Por defecto 2 (Miércoles)
     * @return array Array de fechas en formato 'Y-m-d'
     */
    public static function calculate_delivery_dates($start_date = 'now', $quantity = 4, $frequency_weeks = 1, $delivery_day = 4, $order_cutoff = 2) {
        // Convertir start_date a DateTime si es string
        if ($start_date === 'now' || $start_date === null) {
            $current_date = new DateTime('now', new DateTimeZone('America/El_Salvador'));
        } elseif (is_string($start_date)) {
            $current_date = new DateTime($start_date, new DateTimeZone('America/El_Salvador'));
        } else {
            $current_date = $start_date;
        }

        // Validar valores de días
        $delivery_day = max(0, min(6, intval($delivery_day)));
        $order_cutoff = max(0, min(6, intval($order_cutoff)));

        // Obtener día de la semana (0 = Domingo, 1 = Lunes, ..., 6 = Sábado)
        $day_of_week = (int) $current_date->format('w');
        
        // Convertir a formato donde 0 = Lunes, 6 = Domingo para facilitar cálculos
        $day_index = ($day_of_week === 0) ? 6 : $day_of_week - 1;
        // Lunes=0, Martes=1, Miércoles=2, Jueves=3, Viernes=4, Sábado=5, Domingo=6

        // Convertir delivery_day y order_cutoff al mismo formato (0=Lunes, 6=Domingo)
        $delivery_index = ($delivery_day === 0) ? 6 : $delivery_day - 1;
        $cutoff_index = ($order_cutoff === 0) ? 6 : $order_cutoff - 1;

        // Calcular la primera fecha de entrega
        $first_delivery = clone $current_date;

        if ($day_index < $cutoff_index) {
            // Si es antes del día de corte: Primera entrega es este día de entrega
            if ($day_index <= $delivery_index) {
                // El día de entrega aún no ha pasado esta semana
                $days_to_add = $delivery_index - $day_index;
            } else {
                // El día de entrega ya pasó esta semana, ir al siguiente
                $days_to_add = (7 - $day_index) + $delivery_index;
            }
            $first_delivery->modify("+{$days_to_add} days");
        } else {
            // Si es en o después del día de corte: Primera entrega es el día de entrega de la próxima semana
            $days_to_add = (7 - $day_index) + $delivery_index;
            $first_delivery->modify("+{$days_to_add} days");
        }

        // Asegurar que la hora sea medianoche
        $first_delivery->setTime(0, 0, 0);

        // Generar array de fechas
        $delivery_dates = array();
        $current_delivery = clone $first_delivery;

        for ($i = 0; $i < $quantity; $i++) {
            $delivery_dates[] = $current_delivery->format('Y-m-d');
            
            // Calcular siguiente entrega sumando la frecuencia
            if ($i < $quantity - 1) {
                $current_delivery->modify("+{$frequency_weeks} weeks");
            }
        }

        return $delivery_dates;
    }

    /**
     * Calcula la próxima fecha de renovación
     * Basado en la última entrega programada + frecuencia
     *
     * @param string $last_delivery_date Última fecha de entrega en formato 'Y-m-d'
     * @param int $frequency_weeks Frecuencia en semanas
     * @return string Fecha de renovación en formato 'Y-m-d'
     */
    public static function calculate_next_renewal_date($last_delivery_date, $frequency_weeks = 1) {
        $date = new DateTime($last_delivery_date, new DateTimeZone('America/El_Salvador'));
        $date->modify("+{$frequency_weeks} weeks");
        return $date->format('Y-m-d');
    }

    /**
     * Verifica si una fecha es un Jueves
     *
     * @param string|DateTime $date
     * @return bool
     */
    public static function is_thursday($date) {
        if (is_string($date)) {
            $date = new DateTime($date);
        }
        return (int) $date->format('w') === 4; // 4 = Jueves en formato de WordPress
    }

    /**
     * Obtiene el próximo Jueves desde una fecha dada
     *
     * @param string|DateTime $from_date
     * @return DateTime
     */
    public static function get_next_thursday($from_date = 'now') {
        if ($from_date === 'now' || $from_date === null) {
            $date = new DateTime('now', new DateTimeZone('America/El_Salvador'));
        } elseif (is_string($from_date)) {
            $date = new DateTime($from_date, new DateTimeZone('America/El_Salvador'));
        } else {
            $date = $from_date;
        }

        $day_of_week = (int) $date->format('w');
        $day_index = ($day_of_week === 0) ? 6 : $day_of_week - 1;

        if ($day_index < 3) {
            // Antes del Jueves: ir a este Jueves
            $days_to_add = 3 - $day_index;
        } else {
            // Jueves o después: ir al siguiente Jueves
            $days_to_add = (7 - $day_index) + 3;
        }

        $date->modify("+{$days_to_add} days");
        $date->setTime(0, 0, 0);

        return $date;
    }
}
