<?php

declare( strict_types = 1 );

/**
 * Translations for connections without user-defined functions.
 *
 * The MySQL-on-SQLite driver emulates a number of MySQL functions with
 * user-defined SQL functions implemented in PHP. A remote SQLite database
 * (Cloudflare D1, Turso over HTTP) cannot run PHP callbacks, so on such a
 * connection those functions are rewritten to plain SQLite expressions where
 * possible, and some can only be rewritten when their arguments are constant
 * literals, which are then evaluated in PHP at translation time.
 *
 * WP_MySQL_On_SQLite owns the parse tree and calls in here from the few
 * places where a UDF would otherwise be emitted; this class never sees the
 * tree, only translated SQLite fragments.
 *
 * @access private
 */
final class WP_SQLite_UDF_Free_Translator {
	/**
	 * The connection, for quoting literal values.
	 *
	 * @var WP_SQLite_Connection_Interface
	 */
	private WP_SQLite_Connection_Interface $connection;

	/**
	 * Creates the driver's "not supported" exception for a cause.
	 *
	 * @var callable(string): WP_MySQL_On_SQLite_Exception
	 */
	private $exception_factory;

	/**
	 * Constructor.
	 *
	 * @param WP_SQLite_Connection_Interface                $connection        The connection.
	 * @param callable(string): WP_MySQL_On_SQLite_Exception $exception_factory Creates the driver's "not supported"
	 *                                                                         exception for a cause.
	 */
	public function __construct( WP_SQLite_Connection_Interface $connection, callable $exception_factory ) {
		$this->connection        = $connection;
		$this->exception_factory = $exception_factory;
	}

	/**
	 * Translate a MySQL "LIKE BINARY" pattern to a GLOB pattern.
	 *
	 * With user-defined functions the driver converts the pattern at query
	 * time with "_helper_like_to_glob_pattern()". Without them, a constant
	 * pattern is converted in PHP at translation time.
	 *
	 * @param  string $pattern The translated SQLite pattern expression.
	 * @return string          The "GLOB ..." operator fragment.
	 * @throws WP_MySQL_On_SQLite_Exception When the pattern is not constant.
	 */
	public function translate_like_binary( string $pattern ): string {
		// A trailing COLLATE clause doesn't change the literal value.
		$literal = $pattern;
		if ( 1 === preg_match( '/^(.*) COLLATE BINARY$/', $literal, $matches ) ) {
			$literal = $matches[1];
		}
		$pattern_value = $this->get_string_literal_value( $literal );
		if ( null === $pattern_value ) {
			throw $this->new_exception(
				'LIKE BINARY with a non-constant pattern (the connection does not support user-defined functions)'
			);
		}
		$helper = new WP_SQLite_PDO_User_Defined_Functions();
		// The helper answers null only to a null pattern, which this is not.
		$glob = $helper->_helper_like_to_glob_pattern( $pattern_value ) ?? '';
		return 'GLOB ' . $this->quote( $glob ) . ' COLLATE BINARY';
	}

	/**
	 * Translate a MySQL "IF(condition, then, else)" call.
	 *
	 * A CASE expression is the portable equivalent: SQLite only gained IIF()
	 * in 3.32.0, and an "if" alias for it in 3.48.0. As in MySQL, a NULL
	 * condition selects the "else" branch.
	 *
	 * @param  string $condition The translated condition.
	 * @param  string $then      The translated "then" expression.
	 * @param  string $otherwise The translated "else" expression.
	 * @return string            The CASE expression.
	 */
	public function translate_if( string $condition, string $then, string $otherwise ): string {
		return sprintf( 'CASE WHEN %s THEN %s ELSE %s END', $condition, $then, $otherwise );
	}

	/**
	 * Translate a MySQL function that is normally emulated with a user-defined
	 * SQL function to a plain SQLite expression.
	 *
	 * Some functions can only be rewritten when their arguments are constant
	 * string literals; these are then evaluated in PHP at translation time.
	 *
	 * @param  string   $name The uppercase MySQL function name.
	 * @param  string[] $args The translated SQLite argument expressions.
	 * @return string|null    The rewritten SQLite expression, or null when
	 *                        the function is not emulated with a UDF and
	 *                        should be passed through unchanged.
	 * @throws WP_MySQL_On_SQLite_Exception When the function cannot be used
	 *                                    without user-defined functions.
	 */
	public function translate_function_call( string $name, array $args ): ?string {
		switch ( $name ) {
			case 'MONTH':
			case 'MONTHNUM':
				return sprintf( "CAST(STRFTIME('%%m', %s) AS INTEGER)", $args[0] );
			case 'YEAR':
				return sprintf( "CAST(STRFTIME('%%Y', %s) AS INTEGER)", $args[0] );
			case 'DAY':
			case 'DAYOFMONTH':
				return sprintf( "CAST(STRFTIME('%%d', %s) AS INTEGER)", $args[0] );
			case 'HOUR':
				return sprintf( "CAST(STRFTIME('%%H', %s) AS INTEGER)", $args[0] );
			case 'MINUTE':
				return sprintf( "CAST(STRFTIME('%%M', %s) AS INTEGER)", $args[0] );
			case 'SECOND':
				return sprintf( "CAST(STRFTIME('%%S', %s) AS INTEGER)", $args[0] );
			case 'DAYOFWEEK':
				// MySQL: 1 = Sunday. SQLite "%w": 0 = Sunday.
				return sprintf( "(CAST(STRFTIME('%%w', %s) AS INTEGER) + 1)", $args[0] );
			case 'WEEKDAY':
				// MySQL: 0 = Monday. SQLite "%w": 0 = Sunday.
				return sprintf( "((CAST(STRFTIME('%%w', %s) AS INTEGER) + 6) %% 7)", $args[0] );
			case 'WEEK':
				/*
				 * Mode 0 (weeks starting on Sunday) maps to the SQLite "%U"
				 * week number. Mode 1 (weeks starting on Monday, week 1 is
				 * the first week with 4+ days in the year) matches the ISO
				 * week number, except in the week spanning the year
				 * boundary, where MySQL yields 0 (early January) or 53
				 * (late December) instead of the other year's ISO week
				 * number. The legacy PHP emulation had the same divergence.
				 *
				 * The ISO week is computed from the day-of-year of the
				 * Thursday in the date's week ("STRFTIME('%V')" would need
				 * SQLite 3.46+).
				 *
				 * Mode 0 is computed the same way "%U" is defined, for the
				 * same reason: that specifier also needs SQLite 3.46+.
				 * With a 0-based day of the year and a 0-based weekday
				 * starting on Sunday, the week number is
				 * "(day_of_year + 7 - weekday) / 7", truncated.
				 */
				$mode = $args[1] ?? '0';
				if ( '0' === $mode ) {
					return sprintf(
						"((CAST(STRFTIME('%%j', %1\$s) AS INTEGER) + 6 - CAST(STRFTIME('%%w', %1\$s) AS INTEGER)) / 7)",
						$args[0]
					);
				}
				if ( '1' === $mode ) {
					return sprintf(
						"((CAST(STRFTIME('%%j', DATE(%s, '-3 days', 'weekday 4')) AS INTEGER) - 1) / 7 + 1)",
						$args[0]
					);
				}
				throw $this->new_exception(
					'WEEK() with a mode other than 0 or 1 (the connection does not support user-defined functions)'
				);
			case 'UNIX_TIMESTAMP':
				// STRFTIME('%s') rather than UNIXEPOCH(), which needs SQLite 3.38+.
				if ( 0 === count( $args ) ) {
					return "CAST(STRFTIME('%s', 'now') AS INTEGER)";
				}
				return sprintf( "CAST(STRFTIME('%%s', %s) AS INTEGER)", $args[0] );
			case 'FROM_UNIXTIME':
				if ( 1 === count( $args ) ) {
					return sprintf( "DATETIME(%s, 'unixepoch')", $args[0] );
				}
				$format = $this->get_string_literal_value( $args[1] );
				if ( null !== $format ) {
					$format = $this->convert_mysql_date_format_to_strftime( $format );
				}
				if ( null === $format ) {
					throw $this->new_exception(
						'FROM_UNIXTIME() with a non-constant or unsupported format (the connection does not support user-defined functions)'
					);
				}
				return sprintf(
					"STRFTIME(%s, %s, 'unixepoch')",
					$this->quote( $format ),
					$args[0]
				);
			case 'NOW':
			case 'LOCALTIME':
			case 'LOCALTIMESTAMP':
			case 'UTC_TIMESTAMP':
				return "DATETIME('now')";
			case 'CURDATE':
			case 'UTC_DATE':
				return "DATE('now')";
			case 'UTC_TIME':
				return "TIME('now')";
			case 'ISNULL':
				return sprintf( '(%s IS NULL)', $args[0] );
			case 'FIELD':
				$fragment = sprintf( 'CASE %s', $args[0] );
				for ( $i = 1; $i < count( $args ); $i++ ) {
					$fragment .= sprintf( ' WHEN %s THEN %d', $args[ $i ], $i );
				}
				return $fragment . ' ELSE 0 END';
			case 'LEAST':
				return 'MIN(' . implode( ', ', $args ) . ')';
			case 'GREATEST':
				return 'MAX(' . implode( ', ', $args ) . ')';
			case 'LOCATE':
				if ( 2 === count( $args ) ) {
					return sprintf( 'INSTR(%s, %s)', $args[1], $args[0] );
				}
				// A CASE expression rather than IIF(), which needs SQLite 3.32+.
				return sprintf(
					'CASE WHEN INSTR(SUBSTR(%2$s, %3$s), %1$s) = 0 THEN 0 ELSE INSTR(SUBSTR(%2$s, %3$s), %1$s) + %3$s - 1 END',
					$args[0],
					$args[1],
					$args[2]
				);
			case 'LOG':
				/*
				 * SQLite's LN() is one of the math functions, available only
				 * from SQLite 3.35.0 and only when the library was compiled
				 * with SQLITE_ENABLE_MATH_FUNCTIONS, which is not guaranteed.
				 * Constant arguments are evaluated in PHP so that the common
				 * cases work on every supported SQLite build; other arguments
				 * still compile to LN() and need a math-enabled library.
				 */
				$constants = array();
				foreach ( $args as $arg ) {
					$constants[] = $this->get_numeric_literal_value( $arg );
				}
				if ( ! in_array( null, $constants, true ) ) {
					return $this->evaluate_logarithm( $constants );
				}
				if ( 1 === count( $args ) ) {
					return sprintf( 'LN(%s)', $args[0] );
				}
				return sprintf( '(LN(%s) / LN(%s))', $args[1], $args[0] );
			case 'DATEDIFF':
				return sprintf(
					'CAST(JULIANDAY(DATE(%s)) - JULIANDAY(DATE(%s)) AS INTEGER)',
					$args[0],
					$args[1]
				);
			case 'UCASE':
				return sprintf( 'UPPER(%s)', $args[0] );
			case 'LCASE':
				return sprintf( 'LOWER(%s)', $args[0] );
			case 'UNHEX':
				// A native SQLite function since 3.41.0.
				return 'UNHEX(' . implode( ', ', $args ) . ')';
			case 'INET_NTOA':
				return sprintf(
					"PRINTF('%%d.%%d.%%d.%%d', (%1\$s >> 24) & 255, (%1\$s >> 16) & 255, (%1\$s >> 8) & 255, %1\$s & 255)",
					$args[0]
				);
			case 'GET_LOCK':
			case 'RELEASE_LOCK':
				// Advisory locks are meaningless on a single-writer database.
				return '1';
			case 'MD5':
			case 'REVERSE':
			case 'TO_BASE64':
			case 'FROM_BASE64':
			case 'INET_ATON':
				// These can be evaluated in PHP for constant arguments.
				$value = $this->get_string_literal_value( $args[0] );
				if ( null === $value ) {
					throw $this->new_exception(
						sprintf(
							'%s() with a non-constant argument (the connection does not support user-defined functions)',
							$name
						)
					);
				}
				switch ( $name ) {
					case 'MD5':
						$result = md5( $value );
						break;
					case 'REVERSE':
						$result = strrev( $value );
						break;
					case 'TO_BASE64':
						$result = base64_encode( $value );
						break;
					case 'FROM_BASE64':
						$result = base64_decode( $value );
						break;
					default:
						$result = (string) ip2long( $value );
						break;
				}
				return $this->quote( $result );
			case 'REGEXP':
			case 'RLIKE':
				throw $this->new_exception(
					'REGEXP (the connection does not support user-defined functions)'
				);
		}
		return null;
	}

	/**
	 * Convert a MySQL date format to an SQLite STRFTIME format.
	 *
	 * @param  string $format The MySQL date format.
	 * @return string|null    The STRFTIME format, or null when the format
	 *                        includes unsupported format specifiers.
	 */
	private function convert_mysql_date_format_to_strftime( string $format ): ?string {
		$map = array(
			'%Y' => '%Y', // Year, four digits.
			'%m' => '%m', // Month, two digits.
			'%c' => '%m', // Month (MySQL: without zero padding).
			'%d' => '%d', // Day of the month, two digits.
			'%e' => '%e', // Day of the month, without zero padding.
			'%H' => '%H', // Hour (00-23).
			'%k' => '%k', // Hour (0-23).
			'%h' => '%I', // Hour (01-12).
			'%I' => '%I', // Hour (01-12).
			'%l' => '%l', // Hour (1-12).
			'%i' => '%M', // Minutes, two digits.
			'%S' => '%S', // Seconds, two digits.
			'%s' => '%S', // Seconds, two digits.
			'%p' => '%p', // AM or PM.
			'%j' => '%j', // Day of the year, three digits.
			'%w' => '%w', // Day of the week (0 = Sunday).
			'%T' => '%H:%M:%S',
			'%%' => '%%',
		);

		$result = '';
		$length = strlen( $format );
		for ( $i = 0; $i < $length; $i++ ) {
			if ( '%' !== $format[ $i ] ) {
				$result .= $format[ $i ];
				continue;
			}
			$token = substr( $format, $i, 2 );
			if ( ! isset( $map[ $token ] ) ) {
				return null;
			}
			$result .= $map[ $token ];
			$i      += 1;
		}
		return $result;
	}

	/**
	 * Extract the value of a translated SQLite string literal expression.
	 *
	 * @param  string $expression The translated SQLite expression.
	 * @return string|null        The string value, or null when the
	 *                            expression is not a plain string literal.
	 */
	private function get_string_literal_value( string $expression ): ?string {
		if ( 1 === preg_match( "/^'((?:[^']|'')*)'$/", $expression, $matches ) ) {
			return str_replace( "''", "'", $matches[1] );
		}
		return null;
	}

	/**
	 * Extract the value of a translated SQLite numeric literal expression.
	 *
	 * @param  string $expression The translated SQLite expression.
	 * @return float|null         The numeric value, or null when the
	 *                            expression is not a plain numeric literal.
	 */
	private function get_numeric_literal_value( string $expression ): ?float {
		if ( 1 === preg_match( '/^-?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][-+]?[0-9]+)?$/', $expression ) ) {
			return (float) $expression;
		}
		return null;
	}

	/**
	 * Evaluate a MySQL LOG() call over constant arguments.
	 *
	 * As in MySQL, out-of-domain arguments produce NULL rather than an error:
	 * a non-positive value, and for the two-argument form, a base that is not
	 * positive or that equals 1.
	 *
	 * @param  float[] $args The constant argument values, as per MySQL LOG().
	 * @return string        The SQLite literal for the result.
	 */
	private function evaluate_logarithm( array $args ): string {
		if ( 1 === count( $args ) ) {
			$base  = M_E;
			$value = $args[0];
		} else {
			$base  = $args[0];
			$value = $args[1];
		}

		if ( $value <= 0 || $base <= 0 || 1.0 === $base ) {
			return 'NULL';
		}

		$result = M_E === $base ? log( $value ) : log( $value ) / log( $base );

		/*
		 * A locale-independent representation with enough precision to
		 * round-trip a double, matching how SQLite renders REAL values.
		 */
		return rtrim( rtrim( sprintf( '%.17G', $result ), '0' ), '.' );
	}

	/**
	 * Compose an SQLite expression that raises an error when evaluated.
	 *
	 * This is used for strict mode data validation, where invalid values
	 * must reject the whole statement at execution time.
	 *
	 * With user-defined function support the driver uses its "THROW"
	 * function. Without it, a malformed JSON error is raised instead: the
	 * message expression is embedded in an invalid JSON document passed to
	 * JSON_EXTRACT(). Referencing the message expression (which involves
	 * column values) keeps the expression non-constant, so SQLite evaluates
	 * it only in the failing branch. The exact error message fidelity is
	 * lost on such connections.
	 *
	 * @param  string $message_expression An SQLite expression composing the error message.
	 * @return string                     An SQLite expression raising an error when evaluated.
	 */
	public function compose_error_expression( string $message_expression ): string {
		return sprintf( "JSON_EXTRACT('[' || (%s), '$')", $message_expression );
	}

	/**
	 * Quote a string value for use in an SQLite query.
	 *
	 * @param  string $value The value.
	 * @return string        The quoted value.
	 */
	private function quote( string $value ): string {
		return $this->connection->quote( $value );
	}

	/**
	 * Create the driver's "not supported" exception.
	 *
	 * @param  string $cause The cause.
	 * @return WP_MySQL_On_SQLite_Exception The exception.
	 */
	private function new_exception( string $cause ): WP_MySQL_On_SQLite_Exception {
		return ( $this->exception_factory )( $cause );
	}
}
