
<?php

    require_once "const.php";
    $Class = new ConstData();
    header_func();
    //------------------------------------------------------------------------------------
        /*接続 */
        $pg_conn = pg_connect(ConstData::DB_DATA);
        $json_data = file_get_contents("php://input");
        $array_data = json_decode($json_data);
    //------------------------------------------------------------------------------------

    try{
        // Auth0認証
        $getAccessToken = $array_data -> access_token;
        $getAlgorithm = $array_data -> algorithm;
        $checkedData = $Class -> auth0Chek($getAccessToken,$getAlgorithm);
        // 認証に成功したら
        if($checkedData["data"]){

            /////////////////////////////////////////////////////////////
            // ユーザー情報取得のために必要な設定 //////////////////////////
                // user_management用
                $getExternalHeaders = array(
                    "Authorization: Bearer ".SecretData::EXTERNAL_DB_KEY,
                    "Content-type: application/json"
                );
            /////////////////////////////////////////////////////////////
            /////////////////////////////////////////////////////////////



            // メンテナンス状況のチェック ///////////////////////////////////////
            $loginUserAuthority = $array_data->loginUserAuthority;
            // メンテナンス情報の取得
            $result = pg_query("SELECT * FROM maintenance WHERE id = 1 ");
            $data = pg_fetch_all($result);
            // メンテナンス中かつ開発者じゃない場合
            if($data[0]["flag"] != 0 && $loginUserAuthority != 1 ){
                $all_data = [      
                    "data"=> false,
                    "reDirect"=> "./home",
                    "reDirect"=> "./maintenance",
                    "message"=> "メンテナンス中のため実行できませんでした。\n5秒後にメンテナンスページに遷移します。", 
                ];
            }else{
                //呼び出すAPIのフラグ
                $flag = $array_data -> flag;
                // クエリの開始
                pg_query($pg_conn,"BEGIN");
    
                switch($flag){
                    // ユーザーテーブルと結合してデータ取得
                    case 'test':
                        try
                        {
                            ////////////////////////////////////////////////////////////////////
                            // ユーザー情報の取得と、一時的テーブルの作成 //////////////////////////
                                // curlのセッションを初期化する
                                $ch = curl_init();
                                // curlのオプションを設定する
                                $options = array(
                                CURLOPT_URL => 'https://system.syowa.com/user-management/api/'.ConstData::API_VER.'/user',
                                CURLOPT_RETURNTRANSFER => true,
                                CURLOPT_HTTPHEADER => $getExternalHeaders,
                                );
                                curl_setopt_array($ch, $options);
                                // curlを実行し、レスポンスデータを保存する
                                $response  = curl_exec($ch);
                                $user_arr = json_decode($response,true);
                                // curlセッションを終了する
                                curl_close($ch);

                                //一時的なテーブルの作成
                                pg_query("
                                CREATE TEMP TABLE temp_user_table(
                                    user_id INTEGER,
                                    user_name TEXT
                                    )
                                ");
                        
                                // 一時的なテーブルにユーザー情報を挿入
                                foreach ($user_arr["data"] as $userData) {
                                //var_dump($orderData);
                                pg_query("
                                    INSERT INTO temp_user_table(
                                        user_id,
                                        user_name
                                    )
                                    VALUES (
                                        '{$userData["userId"]}', 
                                        '{$userData["familyName"]} {$userData["givenName"]}'
                                    )
                                ");
                                }
                            ////////////////////////////////////////////////////////////////////
                            ////////////////////////////////////////////////////////////////////
                            
                            // マスター情報取得クエリ
                            $sql = 'SELECT
                                a.reserve_id, --予約ID
                                a.use_start_day, --予約日
                                a.start_time, --予約開始時間
                                a.end_time, --予約終了時間
                                a.driver, --運転者
                                a.place, --場所
                                a.number_of_people, --人数
                                a.luggage, --荷物
                                a.memo, --備考
                                b.car_name, --車名
                                b.car_no, --ナンバー
                                b.garages, --車の所在
                                a.etc, --ETC
                                c.user_name as created_user_name, --作成者
                                d.user_name as edit_user_name, --編集者
                                e.user_name as cancel_user_name --キャンセル者
                                FROM reserve a
                                LEFT JOIN cars b ON a.car_id = b.car_id
                                LEFT JOIN temp_user_table c ON a.created_user_id = c.user_id --一時的なユーザーテーブルと結合する
                                LEFT JOIN temp_user_table d ON a.edit_user_id = d.user_id --一時的なユーザーテーブルと結合する
                                LEFT JOIN temp_user_table e ON a.cancel_user_id = e.user_id --一時的なユーザーテーブルと結合する
                                ORDER BY reserve_id ASC;   
                            ';
                            // 実行
                            $result = pg_query($sql);
                            $selectData = pg_fetch_all($result);
                            //オブジェクト配列
                            $all_data = ['data' => $selectData ]; 
        
                            //クエリのコミット
                            pg_query($pg_conn,"COMMIT");
    
                        } 
                        catch (Exception $e) {
    
                            var_dump($ex);
    
                            // クエリのロールバック
                            pg_query($pg_conn,"ROLLBACK");
                            pg_close($pg_conn);
    
                        }

                    break;
                    
                }                     
            }
        }
        
        // 認証に失敗したら
        else{
            // チェックの中身を返す
            $all_data = $checkedData; 
        
        }

    } catch(Exception $e) {
        
        $all_data = array($e);
    }

    //------------------------------------------------------------------------------------
        /*フロントへ返す*/
        $json_value = json_encode($all_data);
        header("Content-Type: application/json; charset=utf-8");
        print_r($json_value);
    //------------------------------------------------------------------------------------

?>
