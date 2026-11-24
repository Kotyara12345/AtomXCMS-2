<?php

/**
 * Order state labels and customer cancellation rules.
 */
class ShopOrderStatus
{
    public static function all()
    {
        return array(
            'new' => 'Новый',
            'confirmed' => 'Подтверждён',
            'paid' => 'Оплачен',
            'shipped' => 'Отправлен',
            'completed' => 'Выполнен',
            'returned' => 'Возврат принят',
            'refunded' => 'Возвращены деньги',
            'cancelled' => 'Отменён',
        );
    }

    public static function label($status)
    {
        $statuses = self::all();
        return isset($statuses[$status]) ? $statuses[$status] : $status;
    }

    public static function customerCanCancel($order)
    {
        if (empty($order)) return false;
        if ((int)(isset($order['stock_released']) ? $order['stock_released'] : 0) !== 0) return false;
        return in_array((string)$order['status'], array('new', 'confirmed'), true);
    }
}
