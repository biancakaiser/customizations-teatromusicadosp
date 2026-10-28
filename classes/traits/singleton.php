<?php

namespace TeatroMusicadoSP\Customizations\Traits;

defined('ABSPATH') || exit;

trait Singleton
{
    private static $instance = null;

    final public static function get_instance(): static
    {
        if (null === self::$instance) {
            self::$instance = new static();
        }

        return self::$instance;
    }

    final private function __construct() {
		$this->init();
	}

    private function __clone()
    {
    }

    final public function __wakeup(): void
    {
        throw new \LogicException(
            'Não é permitido desserializar esta instância.'
        );
    }

	protected function init() {}
}