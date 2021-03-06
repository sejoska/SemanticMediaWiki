/**
 * @license GNU GPL v2+
 * @since 2.5
 *
 * @author mwjames
 */

/*global jQuery, mediaWiki, mw, smw */
( function ( $, mw ) {

	'use strict';

	/**
	 * @since  2.5.0
	 * @constructor
	 *
	 * @param {Object} mwApi
	 * @param {Object} util
	 *
	 * @return {this}
	 */
	var browse = function ( mwApi ) {

		this.VERSION = "2.5.0";
		this.api = mwApi;

		return this;
	};

	/**
	 * @since 2.5
	 * @method
	 *
	 * @param {Object} context
	 */
	browse.prototype.setContext = function( context ) {
		this.context = context;
	}

	/**
	 * @since 2.5
	 * @method
	 */
	browse.prototype.doApiRequest = function() {

		var self = this,
			subject = self.context.data( 'subject' ),
			options = JSON.stringify( self.context.data( 'options' ) );

		// Expect a serialization format (see DIWikiPage::getHash)
		if ( subject.indexOf( "#" ) == -1 ) {
			return self.reportError( mw.msg( 'smw-browse-api-subject-serialization-invalid' ) );
		}

		subject = subject.split( "#" );

		self.api.post( {
			action: "browsebysubject",
			subject: subject[0],
			ns: subject[1],
			iw: subject[2],
			subobject: subject[3],
			options: options,
			type: 'html'
		} ).done( function( data ) {
			self.appendContent( data.query );
		} ).fail ( function( xhr, status, error ) {

			var text = 'The API encountered an unknown error';

			if ( status.hasOwnProperty( 'xhr' ) ) {
				var xhr = status.xhr;

				if ( xhr.hasOwnProperty( 'responseText' ) ) {
					text = xhr.responseText.replace(/\<br \/\>/g," " );
				};

				if ( xhr.hasOwnProperty( 'statusText' ) ) {
					text = 'The API returned with: ' + xhr.statusText.replace(/\<br \/\>/g," " );
				};
			}

			if ( status.hasOwnProperty( 'error' ) ) {
				text = status.error.code + ': ' + status.error.info;
			}

			self.reportError( text );
		} );
	}

	/**
	 * @since 2.5
	 * @method
	 *
	 * @param {string} error
	 */
	browse.prototype.reportError = function( error ) {
		this.context.find( '.smwb-status' ).append( error ).addClass( 'smw-callout smw-callout-error' );
	}

	/**
	 * @since 2.5
	 * @method
	 *
	 * @param {string} content
	 */
	browse.prototype.appendContent = function( content ) {

		var self = this;

		self.context.find( '.smwb-emptysheet' ).replaceWith( content );

		var form = self.context.find( '.smwb-form' );

		mw.loader.using( [ 'ext.smw.browse', 'ext.smw.browse.autocomplete' ] ).done( function () {
			form.trigger( 'smw.article.autocomplete' , {
				'context': form
			} );
		} );

		mw.loader.load(
			self.context.find( '.smwb-modules' ).data( 'modules' )
		);

		// Re-apply JS-component instances on new content
		// Trigger an event
		mw.hook( 'smw.browse.apiparsecomplete' ).fire( self.context );

		$( document ).trigger( 'SMW::Browse::ApiParseComplete' , {
			'context': self.context
		} );
	}

	var instance = new browse(
		new mw.Api()
	);

	$( document ).ready( function() {

		$( '.smwb-container' ).each( function() {
			instance.setContext( $( this ) );
			instance.doApiRequest();
		} );

		var form = $( this ).find( '.smwb-form' );

		mw.loader.using( [ 'ext.smw.browse', 'ext.smw.browse.autocomplete' ] ).done( function () {
			form.trigger( 'smw.article.autocomplete' , {
				'context': form
			} );
		} );

	} );

}( jQuery, mediaWiki ) );
