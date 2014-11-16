<?php
/*!
* HybridAuth
* http://hybridauth.sourceforge.net | http://github.com/hybridauth/hybridauth
* (c) 2009-2014, HybridAuth authors | http://hybridauth.sourceforge.net/licenses.html 
*/

namespace Hybridauth\Adapter;

use Hybridauth\Data;
use Hybridauth\HttpClient;
use Hybridauth\Exception;
use Hybridauth\User;

use Hybridauth\Thirdparty\OpenID\LightOpenID;

/**
 * This class  can be used to simplify the authentication flow of OpenID based service providers.
 *
 * Subclasses (i.e., providers adapters) can either use the already provided methods or override
 * them when necessary.
 */
class OpenID extends AdapterBase implements AdapterInterface 
{
	/**
	* LightOpenID instance
	*
	* @var object
	*/
	protected $openIdClient = null;

	/**
	* Openid provider identifier
	*
	* @var string
	*/
	protected $openidIdentifier = '';

	/**
	* Adapter initializer
	*
	* @throws Exception
	*/
	protected function initialize()
	{
		if( $this->config->exists( 'openid_identifier' ) )
		{
			$this->openidIdentifier = $this->config->get( 'openid_identifier' );
		}

		if( empty( $this->openidIdentifier ) )
		{
			throw new Exception( 'OpenID adapter requires an openid_identifier.', 4 );
		}

		$hostPort = parse_url( $this->endpoint, PHP_URL_PORT );
		$hostUrl  = parse_url( $this->endpoint, PHP_URL_HOST );

		if( $hostPort )
		{
			$hostUrl .= ':' . $hostPort;
		}

		// @fixme: add proxy
		$this->openIdClient = new LightOpenID( $hostUrl, null );
	}

	/**
	* {@inheritdoc}
	*/
	function authenticate()
	{
		if( $this->isAuthorized() )
		{
			return true;
		}

		if( ! isset( $_GET['openid_mode'] ) )
		{
			return $this->authenticateBegin();
		}

		return $this->authenticateFinish();
	}

	/**
	* {@inheritdoc}
	*/
	function isAuthorized()
	{
		return (bool) $this->storage->get( $this->providerId . '.user' );
	}

	/**
	* {@inheritdoc}
	*/
	function disconnect()
	{
		$this->storage->delete( $this->providerId . '.user' );

		return true;
	}

	/**
	* Initiate the authorization protocol
	*
	* Include and instantiate LightOpenID
	*/
	function authenticateBegin()
	{
		$this->openIdClient->identity  = $this->openidIdentifier;
		$this->openIdClient->returnUrl = $this->endpoint;
		$this->openIdClient->required  = array(
			'namePerson/first'       ,
			'namePerson/last'        ,
			'namePerson/friendly'    ,
			'namePerson'             ,
			'contact/email'          ,
			'birthDate'              ,
			'birthDate/birthDay'     ,
			'birthDate/birthMonth'   ,
			'birthDate/birthYear'    ,
			'person/gender'          ,
			'pref/language'          ,
			'contact/postalCode/home',
			'contact/city/home'      ,
			'contact/country/home'   ,

			'media/image/default'    ,
		);

		HttpClient\Util::redirect( $this->openIdClient->authUrl() );
	}

	/**
	* Finalize the authorization process.
	*
	* @throws Exception
	*/
	function authenticateFinish()
	{
		if( $this->openIdClient->mode == 'cancel' )
		{
			throw new Exception( 'Authentication failed! User has cancelled authentication!', 5 );
		}

		if( ! $this->openIdClient->validate() )
		{
			throw new Exception( 'Authentication failed. Invalid request received!', 5 );
		}

		$openidAttributes = $this->openIdClient->getAttributes();

		$userProfile = $this->fetchUserProfile( $openidAttributes );

		/* with openid providers we only get user profiles once, so we store it */
		$this->storage->set( $this->providerId . '.user', $userProfile );
	}

	/**
	* Fetch user profile from received openid attributes
	*/
	protected function fetchUserProfile( $openidAttributes )
	{
		$data = new Data\Collection( $openidAttributes );

		$userProfile = new User\Profile();

		$userProfile->identifier  = $this->openIdClient->identity;

		$userProfile->firstName   = $data->get( 'namePerson/first' );
		$userProfile->lastName    = $data->get( 'namePerson/last' );
		$userProfile->email       = $data->get( 'contact/email' );
		$userProfile->language    = $data->get( 'pref/language' );
		$userProfile->country     = $data->get( 'contact/country/home' );
		$userProfile->zip         = $data->get( 'contact/postalCode/home' );
		$userProfile->gender      = $data->get( 'person/gender' );
		$userProfile->photoURL    = $data->get( 'media/image/default' );
		$userProfile->birthDay    = $data->get( 'birthDate/birthDay' );
		$userProfile->birthMonth  = $data->get( 'birthDate/birthMonth' );
		$userProfile->birthYear   = $data->get( 'birthDate/birthDate' );

		$userProfile = $this->fetchUserGender( $userProfile, $data->get( 'person/gender' ) );
		
		$userProfile = $this->fetchUserDisplayName( $userProfile, $data );

		return $userProfile;
 	}

	/**
	* Extract users display names
	*/
	protected function fetchUserDisplayName( $userProfile, $data )
	{
		$userProfile->displayName = $data->get( 'namePerson' );

		$userProfile->displayName = $userProfile->displayName 
				? $userProfile->displayName : $data->get( 'namePerson/friendly' );
		$userProfile->displayName = $userProfile->displayName 
				? $userProfile->displayName : trim( $userProfile->firstName . ' ' . $userProfile->lastName );

		return $userProfile;
 	}

	/**
	* Extract users gender
	*/
	protected function fetchUserGender( $userProfile, $gender )
	{
		if( 'f' == strtolower( $gender ) )
		{
			$gender = 'female';
		}

		if( 'm' == strtolower( $gender ) )
		{
			$gender = 'male';
		}

		$userProfile->gender = strtolower( $gender );

		return $userProfile;
 	}

	/**
	* OpenID only provide the user profile one. This method will attempt to retrieve the profile from storage.
	*/
	function getUserProfile()
	{
		$userProfile = $this->storage->get( $this->providerId . '.user' );

		if( ! is_object( $userProfile ) )
		{
			throw new Exception( "User profile request failed! User is not connected to {$this->providerId} or his session has expired.", 6 );
		}

		return $userProfile;
	}
}
