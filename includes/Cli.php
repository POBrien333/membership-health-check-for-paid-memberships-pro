<?php
/**
 * WP-CLI command, so the checks can be run over SSH or from a scheduled script.
 *
 * @package MembershipHealthCheck
 */

namespace MembershipHealthCheck;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The WP-CLI command.
 */
final class Cli {

	/**
	 * Run every membership health check.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table (default), csv, json, or count for a bare number of problems.
	 *
	 * [--check=<id>]
	 * : Run one check only, by id.
	 *
	 * ## EXAMPLES
	 *
	 *     wp membership-health
	 *     wp membership-health --check=unbilled_access
	 *     wp membership-health --format=count
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public static function run( $args, $assoc_args ): void {
		if ( ! Plugin::pmpro_active() ) {
			\WP_CLI::error( 'Paid Memberships Pro is not active.' );
		}

		$format = $assoc_args['format'] ?? 'table';
		$only   = $assoc_args['check'] ?? '';

		$results = Checks::run_all();

		if ( '' !== $only ) {
			$results = array_values(
				array_filter(
					$results,
					static function ( $r ) use ( $only ) {
						return $r['id'] === $only;
					}
				)
			);

			if ( ! $results ) {
				\WP_CLI::error( sprintf( 'No check with id "%s".', $only ) );
			}
		}

		$problems = 0;
		$flat     = array();

		foreach ( $results as $r ) {
			if ( Checks::SEVERITY_INFO !== $r['severity'] ) {
				$problems += $r['count'];
			}

			foreach ( $r['rows'] as $row ) {
				$flat[] = array(
					'check'    => $r['id'],
					'severity' => $r['severity'],
					'user_id'  => $row['user_id'] ?? 0,
					'who'      => $row['display_name'] ?? '',
					'email'    => $row['user_email'] ?? '',
					'level'    => $row['level'] ?? '',
					'detail'   => self::detail( $r, $row ),
				);
			}
		}

		if ( 'count' === $format ) {
			\WP_CLI::line( (string) $problems );
			return;
		}

		if ( in_array( $format, array( 'csv', 'json' ), true ) ) {
			\WP_CLI\Utils\format_items( $format, $flat, array( 'check', 'severity', 'user_id', 'who', 'email', 'level', 'detail' ) );
			return;
		}

		foreach ( $results as $r ) {
			if ( 0 === $r['count'] ) {
				\WP_CLI::log( \WP_CLI::colorize( '%gOK%n  ' . $r['label'] ) );
			} else {
				$colour = Checks::SEVERITY_HIGH === $r['severity'] ? '%r' : ( Checks::SEVERITY_MEDIUM === $r['severity'] ? '%y' : '%c' );
				\WP_CLI::log( \WP_CLI::colorize( sprintf( '%s%d%%n  %s', $colour, $r['count'], $r['label'] ) ) );

				foreach ( $r['rows'] as $row ) {
					\WP_CLI::log(
						sprintf(
							'      %-6s %-30.30s %s',
							$row['user_id'] ? '#' . $row['user_id'] : '',
							$row['user_email'] ? $row['user_email'] : ( $row['display_name'] ?? '' ),
							self::detail( $r, $row )
						)
					);
				}
			}

			self::status_table( $r );
		}

		\WP_CLI::log( '' );

		if ( $problems ) {
			\WP_CLI::warning( sprintf( '%d account(s) need attention.', $problems ) );
		} else {
			\WP_CLI::success( 'Nothing needs attention.' );
		}
	}

	/**
	 * A finding, with any extra columns the check defined folded into the text.
	 *
	 * The admin screen gives those columns their own headings. The machine
	 * formats are one flat table across every check, so a column that exists for
	 * only one of them would make the CSV ragged. Prefixing keeps every value
	 * present and the shape square.
	 *
	 * @param array $r   One check result.
	 * @param array $row One finding from it.
	 */
	private static function detail( array $r, array $row ): string {
		$parts = array();

		foreach ( ( empty( $r['columns'] ) ? array() : $r['columns'] ) as $key => $heading ) {
			if ( ! empty( $row[ $key ] ) ) {
				$parts[] = $heading . ': ' . $row[ $key ];
			}
		}

		if ( ! empty( $row['detail'] ) ) {
			$parts[] = (string) $row['detail'];
		}

		return implode( ' — ', $parts );
	}

	/**
	 * A check's standing status panel, if it has one.
	 *
	 * Printed in the readable output whether or not the check found a fault —
	 * the webhook last-received times are worth seeing when nothing is wrong.
	 * The machine formats above carry findings, and a status panel is not one.
	 *
	 * @param array $r One check result.
	 */
	private static function status_table( array $r ): void {
		if ( empty( $r['table']['rows'] ) ) {
			return;
		}

		$keys = array_keys( $r['table']['columns'] );
		$last = end( $keys );

		// Size each column to its widest cell, so the panel lines up whatever a
		// check chooses to put in it.
		$width = array();

		foreach ( $keys as $key ) {
			$width[ $key ] = self::width( (string) $r['table']['columns'][ $key ] );

			foreach ( $r['table']['rows'] as $row ) {
				$width[ $key ] = max( $width[ $key ], self::width( (string) ( $row[ $key ] ?? '' ) ) );
			}
		}

		foreach ( $r['table']['rows'] as $row ) {
			$line = '';

			foreach ( $keys as $key ) {
				$value = (string) ( $row[ $key ] ?? '' );
				$line .= $key === $last
					? $value
					: $value . str_repeat( ' ', $width[ $key ] - self::width( $value ) + 2 );
			}

			\WP_CLI::log( '      ' . $line );
		}
	}

	/**
	 * Display width of a string, counting characters rather than bytes.
	 *
	 * The status panel uses an em dash for "nothing recorded", which is three
	 * bytes and one column.
	 *
	 * @param string $value String to measure.
	 */
	private static function width( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}
}
