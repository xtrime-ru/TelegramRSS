<?php

namespace TelegramRSS;


class Ban {

    private array $clients;
    private int $clientsCount = 0;
    private int $rpmLimit;
    private array $ipBlacklist;
    private const BAN_DURATION_STEPS = [
        1 * 60,
        5 * 60,
        30 * 60,
        60 * 60,
        6 * 60 * 60,
        12 * 60 * 60,
        24 * 60 * 60,
    ];
    private const CLIENTS_LIMIT = 10000;
    private const CLIENTS_TRIM_THRESHOLD = 8000;


    public function __construct() {
        $this->clients = [];
        $this->rpmLimit = Config::getInstance()->get('access.rpm');
        $this->ipBlacklist = array_fill_keys(Config::getInstance()->get('access.ip_blacklist'), null);
    }

    private function disableBan():bool {
        return $this->rpmLimit === 0;
    }

    /**
     * Добавляет в массив клиентов пустую запись
     * @param $ip
     * @return Ban
     */
    private function addIp($ip): self {
        if ($this->disableBan()) return $this;

        $this->clients[$ip] = [
            'requests' => [],
            'rpm' => 0,
            'last_ban_duration' => 0,
            'ban_timestamp' => 0,
            'reason' => '',
            'url' => '',
        ];
        ++$this->clientsCount;
        return $this;
    }

    /**
     * Пересчитывает данные по указанному ip
     *
     * @param string $ip
     * @param string $url
     *
     * @return Ban
     */
    public function updateIp(string $ip, string $url = ''): self {
        if ($this->disableBan()) return $this;
        if (empty($this->clients[$ip]) && empty($this->ipBlacklist[$ip])) {
            $this->addIp($ip);
        } else {
            if ($this->getBan($ip)) {
                //не обновляем если ip в бане
                return $this;
            }
        }

        $this->trimClients();
        $info = &$this->clients[$ip];

        $info['requests'][] = time();

        $minuteAgo = strtotime('-1 minute');
        $info['rpm'] = 0;
        foreach ($info['requests'] as $key => $ts) {
            if ($ts > $minuteAgo) {
                $info['rpm'] += 1;
            } else {
                unset($info['requests'][$key]);
            }
        }

        if ($info['ban_timestamp'] < strtotime('-1 day')) {
            //Обнуляем баны через если за последние сутки небыло банов
            $info['ban_timestamp'] = 0;
            $info['last_ban_duration'] = 0;
        }

        if ($info['rpm'] > $this->rpmLimit) {
            $this->addBan($ip, 'Too many requests', $url);
        }

        return $this;
    }

    /**
     * Банит по ip
     *
     * @param string $ip
     * @param string $reason
     *
     * @param string $url
     *
     * @return Ban
     */
    public function addBan(string $ip, string $reason = '', string $url = ''): self {
        if ($this->disableBan()) return $this;
        $info = &$this->clients[$ip];
        foreach (static::BAN_DURATION_STEPS as $duration) {
            if ($info['last_ban_duration'] < $duration) {
                $info['last_ban_duration'] = $duration;
                break;
            }
        }
        $info['ban_timestamp'] = time() + $info['last_ban_duration'];
        $info['reason'] = $reason;
        $info['url'] = $url;

        return $this;
    }

    /**
     * Возвращает массив с данными о бане.
     * @param $ip
     * @return array|null
     */
    public function getBan($ip): ?array {
        $status = $this->clients[$ip] ?? [];
        if (!$status) {
            return [];
        }
        $timeLeftHuman = '';
        $reason = $status['reason'];
        if (array_key_exists($ip, $this->ipBlacklist)) {
            $reason = 'Blacklist';
            $timeLeftHuman = '9999:00:00';
        }
        $timeLeft = $status['ban_timestamp'] - time();
        if ($timeLeft > 0) {
            $timeLeftHuman = gmdate('H:i:s', $timeLeft);
        }

        if ($timeLeftHuman) {
            return [
                'timeLeft' => $timeLeftHuman,
                'reason' => $reason,
                'url' => $status['url'],
            ];
        }

        return null;
    }

    private function trimClients(): self {
        if ($this->clientsCount > static::CLIENTS_LIMIT) {
            $trimLimit = $this->clientsCount - static::CLIENTS_TRIM_THRESHOLD;
            array_splice($this->clients, 0, $trimLimit);
            $this->clientsCount = static::CLIENTS_TRIM_THRESHOLD;
        }
        return $this;
    }

}