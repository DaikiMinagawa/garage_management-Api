<?php


  require '../../Auth0/const.php';
  require 'vendor/autoload.php';

  function header_func(){

    if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {

      header('Access-Control-Allow-Origin: *');
      header('Access-Control-Allow-Headers: Content-Type');
      header('Access-Control-Allow-Methods: GET, POST');

      exit();

    }

    header('Access-Control-Allow-Origin: *');
    header('Content-type: application/json; charset=UTF-8');

    // キャッシュ関連
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');

  };

    // タイムゾーンを東京に変更
    date_default_timezone_set('Asia/Tokyo');

  class ConstData{
    
    
    // // 本番環境 ///////////////////////////
    // //当システム用DBの接続情報
    //  const DB_DATA = "host=localhost port=5432 dbname=GarageManagement user=postgres password=goodhandsm-and-t";
    // //メールシステム用DB
    // const MAIL_SYSTEM_DB_DATA = "host=localhost port=5432 dbname=mail_service user=postgres password=goodhandsm-and-t";
    // // 外部APIのバージョン
    //  const API_VER = 'v1';
    
    // テスト環境 /////////////////////////
    //当システム用DBの接続情報
    const DB_DATA = "host=localhost port=5432 dbname=GarageManagement user=postgres password=postgres";
    //メールシステム用DB
    const MAIL_SYSTEM_DB_DATA = "host=localhost port=5432 dbname=mail_service user=postgres password=postgres";
    // 外部APIのバージョン
    //const API_VER = 'v1-debug';
    const API_VER = 'v1';
    
    
    // 会社の初期の日付を設定
    const INITIAL_YEAR = '1964';
    const INITIAL_MONTH = '10';
    const INITIAL_DAY = '1';
    
    // 部署
    const HINSYO_DIVISION_ID = 10;

    // システム名称
    const SYS_TITLE = '社有車管理システム';

    function auth0Chek($token,$algorithm){

      if ($token === null || $token === "") {
        return [
          "data"=> false,
          "message"=> 'No `token` request parameter.' 
        ];
        die;
      }
      if (! in_array($algorithm, ['HS256', 'RS256'])) {
        return [
          "data"=> false,
          "message"=> 'Invalid `algorithm` supplied.' 
        ];
        die;
      }
      
      //auth0認証情報設定
      $auth0 = new \Auth0\SDK\Auth0([
        'domain' => "".Auth0_Data::AUTH0_DOMAIN."",
        'clientId' => "".Auth0_Data::CLIENT_ID."",
        'clientSecret' => "".Auth0_Data::CLIENT_SECRET."",
        'audience' => ["".Auth0_Data::AUDIENCE.""],
        'cookieSecret' =>  "".Auth0_Data::COOKIE_SECRET.""
      ]);

      //トークンとAUTH0の認証情報を紐づける
      $token = new \Auth0\SDK\Token($auth0->configuration(), $token, \Auth0\SDK\Token::TYPE_ID_TOKEN);

      //認証情報バリデーション確認。認証に失敗したら例外が出る
      try{
        $token->verify();
        $token->validate();
        //これ以降の処理は認証済みとなる
        
        return [
          "data"=> true,
          "message"=> 'ok' 
        ];

      }catch(Exception $ex){
        return [
          "data"=> false,
          "message"=> 'exception' 
        ];
        // die($ex.message);
        // var_dump($ex);
      }
    }
  };
?>