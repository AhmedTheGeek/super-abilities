<?php
/**
 * Audit module.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Modules;

use SuperAbilities\Abilities\Audit\Audit_Query;
use SuperAbilities\Abilities\Audit\Audit_Summary;
use SuperAbilities\Abstract_Module;
use SuperAbilities\Audit\Context;
use SuperAbilities\Audit\Listener;
use SuperAbilities\Audit\Logger;
use SuperAbilities\Audit\Pruner;

defined( 'ABSPATH' ) || exit;

/**
 * Records every ability call made on this site and exposes the trail as abilities.
 *
 * The log covers abilities registered by other plugins too, and it covers attempts
 * that were refused, which is the part an administrator actually needs when an
 * agent misbehaves.
 *
 * @since 0.1.0
 */
class Audit_Module extends Abstract_Module {

	/**
	 * Row writer, created on boot.
	 *
	 * @since 0.1.0
	 * @var Logger|null
	 */
	protected $logger = null;

	/**
	 * Execution listener, created on boot.
	 *
	 * @since 0.1.0
	 * @var Listener|null
	 */
	protected $listener = null;

	/**
	 * Retention worker, created on boot.
	 *
	 * @since 0.1.0
	 * @var Pruner|null
	 */
	protected $pruner = null;

	/**
	 * Module id.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function id() {
		return 'audit';
	}

	/**
	 * Module label.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Audit trail', 'super-abilities' );
	}

	/**
	 * Module description.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Records every ability call made on this site, including calls to abilities registered by other plugins and attempts that were refused, then exposes that trail as queryable abilities. Only the top level input keys of each call are stored, never the input values.', 'super-abilities' );
	}

	/**
	 * Whether the module is on by default.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function default_enabled() {
		return true;
	}

	/**
	 * Risk level.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function risk() {
		return 'low';
	}

	/**
	 * Abilities this module provides.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, string>
	 */
	public function abilities() {
		return array(
			Audit_Query::class,
			Audit_Summary::class,
		);
	}

	/**
	 * Wires the logger, the listener and the retention cron.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function boot() {
		Context::init();

		$this->logger = new Logger( $this->options() );
		$this->logger->init();

		$this->listener = new Listener( $this->logger );
		$this->listener->init();

		$this->pruner = new Pruner( $this->options() );
		$this->pruner->init();
	}

	/**
	 * The row writer, once the module has booted.
	 *
	 * @since 0.1.0
	 *
	 * @return Logger|null
	 */
	public function logger() {
		return $this->logger;
	}

	/**
	 * The execution listener, once the module has booted.
	 *
	 * @since 0.1.0
	 *
	 * @return Listener|null
	 */
	public function listener() {
		return $this->listener;
	}

	/**
	 * The retention worker, once the module has booted.
	 *
	 * @since 0.1.0
	 *
	 * @return Pruner|null
	 */
	public function pruner() {
		return $this->pruner;
	}
}
