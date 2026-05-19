<?php
/**
 * osTicket Evolution API Notifications - plugin metadata.
 *
 * @license GPL-2.0-or-later
 * @link    https://github.com/RenatoAscencio/osticket-evolution-api
 */

return array(
    'id'          => 'tvplus:evolution-api-notifications',
    'version'     => '0.1.0',
    'ost_version' => '1.17',
    'name'        => /* trans */ 'Evolution API Notifications (WhatsApp)',
    'author'      => 'TVPlus.mx — Renato Ascencio',
    'description' => /* trans */ 'Sends WhatsApp notifications via Evolution API to both end-users (after verifying their phone has WhatsApp) and admin group(s) on ticket lifecycle events. Each event is independently toggleable.',
    'url'         => 'https://github.com/RenatoAscencio/osticket-evolution-api',
    'plugin'      => 'evolution.php:EvolutionApiNotificationsPlugin',
);
