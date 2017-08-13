<?php

namespace SMW\MediaWiki\Specials\Ask;

use ParamProcessor\ParamDefinition;
use SMW\ParameterInput;
use SMWQueryProcessor as QueryProcessor;
use Html;

/**
 * @private
 *
 * @license GNU GPL v2+
 * @since   1.8
 *
 * @author Jeroen De Dauw < jeroendedauw@gmail.com >
 * @author mwjames
 */
class ParametersWidget {

	/**
	 * @var boolean
	 */
	private static $isTooltipDisplay = false;

	/**
	 * @var integer
	 */
	private static $defaultLimit = 50;

	/**
	 * @since 2.5
	 *
	 * @param boolean $isTooltipDisplay
	 */
	public static function setTooltipDisplay( $isTooltipDisplay ) {
		self::$isTooltipDisplay = (bool)$isTooltipDisplay;
	}

	/**
	 * @since 3.0
	 *
	 * @param integer $defaultLimit
	 */
	public static function setDefaultLimit( $defaultLimit ) {
		self::$defaultLimit = $defaultLimit;
	}

	/**
	 * Display a form section showing the options for a given format,
	 * based on the getParameters() value for that format's query printer.
	 *
	 * @since 1.8
	 *
	 * @param string $format
	 * @param array $paramValues The current values for the parameters (name => value)
	 *
	 * @return string
	 */
	public static function parameterList( $format, array $paramValues ) {

		$definitions = QueryProcessor::getFormatParameters( $format );

		$optionsHtml = array();

		/**
		 * @var ParamProcessor\ParamDefinition $definition
		 */
		foreach ( $definitions as $name => $definition ) {

			// Ignore the format parameter, as we got a special control in the GUI for it already.
			if ( $name == 'format' ) {
				continue;
			}

			// Handle sort, order separate as the generated checkbox are suboptimal, and the single
			// field interferes with the GET request on multiple sort setters
			if ( in_array( $name, [ 'sort', 'order' ] ) ) {
				continue;
			}

			// Maybe there is a better way but somehow I couldn't find one therefore
			// 'source' display will be omitted where no alternative source was found or
			// a source that was marked as default but had no other available options
			$allowedValues = $definition->getAllowedValues();
			if ( $name == 'source' && (
					count( $allowedValues ) == 0 ||
					in_array( 'default', $allowedValues ) && count( $allowedValues ) < 2
				) ) {

				continue;
			}

			$currentValue = array_key_exists( $name, $paramValues ) ? $paramValues[$name] : false;
			$dataInfo = $definition->getMessage() !== null ? wfMessage( $definition->getMessage() )->text() : '';

			// Set dafault values
			if ( $name === 'limit' && $currentValue === null ) {
				$currentValue = self::$defaultLimit;
			}

			if ( $name === 'offset' && $currentValue === null ) {
				$currentValue = 0;
			}

			$optionsHtml[] =
				'<td>' .
				Html::rawElement( 'span',
					array(
						'class'     => self::$isTooltipDisplay == true ? 'smw-ask-info' : '',
						'word-wrap' => 'break-word',
						'data-info' => $dataInfo
					),
					htmlspecialchars( $name ) . ': ' ) .
				'</td>' .
				self::option( $definition, $currentValue );
		}

		$i = 0;
		$n = 0;
		$rowHtml = '';
		$resultHtml = '';

		// Top info text for a collapsed option box
		if ( self::$isTooltipDisplay === true ){
			$resultHtml .= Html::element('div', array(
				'style' => 'margin-bottom:10px;'
				), wfMessage( 'smw-ask-otheroptions-info')->text()
			);
		}

		// Table
		$resultHtml .= Html::openElement( 'table', array(
			'class' => 'smw-ask-options-list',
			'width' => '100%'
			)
		);
		$resultHtml .= Html::openElement( 'tbody' );

		while ( $option = array_shift( $optionsHtml ) ) {
			$i++;

			// Collect elements for a row
			$rowHtml .=  $option;

			// Create table row
			if ( $i % 3 == 0 ){
			$resultHtml .= Html::rawElement( 'tr', array(
				'class' => $i % 6 == 0 ? 'smw-ask-options-row-even' : 'smw-ask-options-row-odd',
				), $rowHtml
			);
			$rowHtml = '';
			$n++;
			}
		}

		// Ensure left over elements are collected as well
		$resultHtml .= Html::rawElement( 'tr', array(
			'class' => $n % 2 == 0 ? 'smw-ask-options-row-odd' : 'smw-ask-options-row-even',
			), $rowHtml
		);

		$resultHtml .= Html::closeElement( 'tbody' );
		$resultHtml .= Html::closeElement( 'table' );

		return $resultHtml;
	}

	/**
	 * Get the HTML for a single parameter input
	 *
	 * @since 1.8
	 *
	 * @param ParamDefinition $definition
	 * @param mixed $currentValue
	 *
	 * @return string
	 */
	private static function option( ParamDefinition $definition, $currentValue ) {
		// Init
		$description = '';

		$input = new ParameterInput( $definition );
		$input->setInputName( 'p[' . $definition->getName() . ']' );
		//$input->setInputClass( 'smw-ask-input-' . str_replace( ' ', '-', $definition->getName() ) );

		if ( $currentValue !== false ) {
			$input->setCurrentValue( $currentValue );
		}

		// Parameter description text
		if ( !self::$isTooltipDisplay ) {
			$tooltipInfo = $definition->getMessage() !== null ? wfMessage( $definition->getMessage() )->parse() : '';

			$description =  Html::rawElement( 'span', array(
				'class' => 'smw-ask-parameter-description'
				), '<br />' . $tooltipInfo
			);
		}

		return Html::rawElement( 'td', array(
			'overflow' => 'hidden'
			), $input->getHtml() . $description
		);
	}

}