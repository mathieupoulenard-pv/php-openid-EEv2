<?php

require('../vendor/autoload.php');

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;

const API_VERSION = "54.0";

$app = new Silex\Application();
$app['debug'] = true;

$openidParams = [
	'login_url' => getenv('SF_LOGIN_URL'),
	'client_id' => getenv('CLIENT_ID'),
	'client_secret' => getenv('CLIENT_SECRET'),
	'client_redirect_url' => getenv('CLIENT_REDIRECT_URL')
];

// Register the monolog logging service
$app->register(new Silex\Provider\MonologServiceProvider(), array(
  'monolog.logfile' => 'php://stderr',
));

// Register view rendering
$app->register(new Silex\Provider\TwigServiceProvider(), array(
    'twig.path' => __DIR__.'/views',
));

$session = new Session();
$session->start();
$app['session'] = $session;

$client = HttpClient::create();
$openidConf = $client->request('GET', $openidParams['login_url'].'/.well-known/openid-configuration');

// Our web handlers
$app->get('/', function(Request $request) use($app, $openidParams, $openidConf) {
  if (null !== $autoLogin = $request->query->get('autologin')) {
  		$app['monolog']->addDebug('autologin');
        return $app->redirect($openidConf->toArray()['authorization_endpoint'].'?response_type=code&client_id='.$openidParams['client_id'].'&redirect_uri='.$openidParams['client_redirect_url'].'&state=hp');
  }

  $app['monolog']->addDebug('logging output.');


  // get campagn type
  $userInfo = $app['session']->get('user');
  if(null !== $userInfo) {
  	//dump($userInfo["custom_attributes"]["campaignMembers"]);
  	//dump(json_decode($userInfo["custom_attributes"]["campaignMembers"]));
  }

  return $app['twig']->render('index.twig', [
  		'openidParams' => $openidParams,
  		'openidConf' => $openidConf->getContent(),
  		'openidConfArray' => $openidConf->toArray()
  ]);
});


$app->get('/callback', function(Request $request) use($app, $openidParams, $openidConf) {
  
  $app['monolog']->addDebug('callback output.');

  if (null === $code = $request->query->get('code')) {
  		$app['monolog']->addDebug('no code');
        return $app->redirect('/');
  }

  $client = HttpClient::create();
  $tokenResponse = $client->request('POST', $openidConf->toArray()['token_endpoint'], [
    'body' => [
    	'grant_type' => 'authorization_code',
    	'code' => $code,
    	'client_id' => $openidParams['client_id'],
    	'client_secret' => $openidParams['client_secret'],
    	'redirect_uri' => $openidParams['client_redirect_url']
    ],
  ]);

  if (null === $accessToken = $tokenResponse->toArray()['access_token']) {
  		$app['monolog']->addDebug('no access token');
        return $app->redirect('/');
  }

  $userInfoResponse = $client->request('POST', $openidConf->toArray()['userinfo_endpoint'], [
    'headers' => [
    	'Authorization' => $tokenResponse->toArray()['token_type'] . ' ' . $tokenResponse->toArray()['access_token']
    ],
  ]);

  if (null === $userInfo = $userInfoResponse->toArray()) {
  		$app['monolog']->addDebug('no access token');
        return $app->redirect('/');
  }

  $app['session']->set('token', $tokenResponse->toArray());
  $app['session']->set('accessToken', $tokenResponse->toArray()['access_token']);
  $app['session']->set('user', $userInfo);

  $app['monolog']->addDebug('user connected');

    if ((null !== $show = $app['session']->get('prepareShow')) && (false !== $show = $app['session']->get('prepareShow'))) {
      $app['monolog']->addDebug('no user');
        return $app->redirect('/prepare?show='.$app['session']->get('prepareShow'));
  }

  return $app->redirect('/');

});

$app->get('/logout', function(Request $request) use($app, $openidParams, $openidConf) {

  $app['monolog']->addDebug('logout');
  $app['session']->invalidate();
  return $app->redirect($openidConf->toArray()['end_session_endpoint']);
});


$app->get('/getContactByEBMSId', function(Request $request) use($app) {

  $app['monolog']->addDebug('getContactByEBMSId');


  $client = HttpClient::create();
  $userInfo = $app['session']->get('user');
  $accessToken = $app['session']->get('accessToken');

  // Get contact Info
  $contactResponse = $client->request('GET', preg_replace("/{version}/", API_VERSION, $userInfo["urls"]["sobjects"])."Contact/Tech_Id_Historique__c" . $request->query->get('contactEBMSId'), [
    'headers' => [
      'Authorization' => "Bearer " . $accessToken
    ]
  ]);
  

  dump($contactResponse->toArray());die;

  return $app['twig']->render('contact.twig', [
    'contact' => $contactResponse
]);
});



$app->run();
