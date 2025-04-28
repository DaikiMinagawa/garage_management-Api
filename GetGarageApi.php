<?php
    // constファイルの読み込み
    require_once "const.php";
    $Class = new ConstData();

    // header関数の読み込み
    header_func();



    // DBとの接続 ////////////////////////////////////////////////////////
    
    //接続 
    $pg_conn = pg_connect("".ConstData::DB_DATA."");

    // JSON受信
    $json_data = file_get_contents("php://input");
    // JSONを配列に変換
	$array_data = json_decode($json_data);

    ////////////////////////////////////////////////////////////////////


    //　メンテナンスで使用情報の祖帰化
    $all_data = array();


    // トランザクション
    try{

        // 受け取った配列から認証に必要なデータを取得
        $getAccessToken = $array_data -> access_token;
        $getAlgorithm = $array_data -> algorithm;
    
        // Auth0認証
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

                    /// <summery>
                    // BookingGarage・社有車予約情報入手
                    // </summery>
                    case 'GetGarageReserveInfo':

                        // 日付情報の取り出し
                        $SelectDay = $array_data -> SelectDay;

                        try
                        {
                            
                            // マスター情報取得クエリ
                            $sql_1 = 'SELECT
                                a.car_id as display_car_id,
                                a.car_name,
                                a.car_no,
                                a.garages,
                                a.etc,
                                a.seat_of_number,
                                a.unlimited_day,
                                a.limited_day,
                                

                                -- Nullだったら、空白を入れる
                                COALESCE(b.reserve_id, 0) as reserve_id,
                                COALESCE(b.car_id, 0) as car_id,
                                COALESCE(b.use_start_day, \'\') as use_start_day,
                                COALESCE(b.start_time, \'\') as start_time,
                                COALESCE(b.use_end_day, \'\') as use_end_day,
                                COALESCE(b.end_time, \'\') as end_time,
                                COALESCE(b.driver, \'\') as driver,
                                COALESCE(b.place, \'\') as place,
                                COALESCE(b.number_of_people, \'\') as number_of_people,
                                COALESCE(b.luggage, \'\') as luggage,
                                COALESCE(b.memo, \'\') as memo,
                                COALESCE(b.etc, \'\') as reserve_etc

                                FROM cars a

                                LEFT JOIN reserve b
                                ON a.car_id = b.car_id
                                AND b.cancel_day IS NULL
                                AND b.use_start_day = $1

                                WHERE
                                 (a.use_display = true AND a.un_useble_day IS NULL) 
                                     AND a.unlimited_day <= $1::timestamp
                                     AND (
                                            -- limited_day が NULL の場合は条件をスキップ、それ以外は比較
                                            a.limited_day IS NULL OR a.limited_day >= $1::timestamp
                                        )

                                ORDER BY display_no ASC, display_car_id ASC, b.start_time ASC;
                            ';

                            // $1 = $SelectDay  
                            $params = [$SelectDay];

                            // 実行
                            $result1 = pg_query_params($pg_conn, $sql_1, $params);
                            //$result1 = pg_query($sql_1);

                            $ReserveBookingData = pg_fetch_all($result1);

                            //オブジェクト配列
                            $all_data = ['data' => ['ReseveGarage' => $ReserveBookingData] ]; 

        
                            //クエリのコミット
                            pg_query($pg_conn,"COMMIT");
    
                        } 
                        catch (Exception $ex) {
    
                            var_dump($ex);
    
                            // クエリのロールバック
                            pg_query($pg_conn,"ROLLBACK");
                            pg_close($pg_conn);
    
                        }


                    break;

                    /// <summery>
                    /// 予約情報が重複して無いか確認(新規登録時)
                    /// </summery>
                    case 'CheckDoubleData':

                        // 保存させたいデータ (配列)
                        $SaveCheckDataArray = $array_data->SaveData;

                        try
                        {

                            // ✅ すべての重複データをまとめて格納する配列
                            $allDoubleData = [];

                            // ループ処理で複数レコードを保存
                            foreach ($SaveCheckDataArray as $SaveData) {

                                $CarId = $SaveData->car_id;
                                $UseStartDay = $SaveData->startDate;
                                $StartTime = $SaveData->startTime;
                                $UseEndDay = $SaveData->endDate;
                                $UseEndTime = $SaveData->endTime;


                                // マスター情報取得クエリ
                                $sql_1 = "
                                SELECT
                                    use_start_day,
                                    start_time,
                                    end_time,
                                    driver
                                FROM reserve
                                WHERE
                                car_id = $1
                                AND cancel_day IS NULL
                                AND (
                                    (use_start_day || ' ' || start_time) < $2
                                    AND
                                    (use_end_day || ' ' || end_time) > $3
                                )
                                ORDER BY use_start_day ASC, start_time ASC;";

                                // $1 = $CarId $2 = "$UseEndDay $UseEndTime" $3 = "$UseStartDay $StartTime"
                                $params = [
                                    $CarId,
                                    "$UseEndDay $UseEndTime",
                                    "$UseStartDay $StartTime"
                                ];

                                // 実行
                                $result1 = pg_query_params($pg_conn, $sql_1, $params);

                                $rows = pg_fetch_all($result1);

                                // ✅ 結果が false（0件）なら空配列としてスキップ、それ以外はマージ
                                if ($rows !== false) {
                                    $allDoubleData = array_merge($allDoubleData, $rows);
                                }
                            }
        
                                //オブジェクト配列
                                $all_data = ['data' => ['CheckData' => $allDoubleData] ]; 
        
            
                                //クエリのコミット
                                pg_query($pg_conn,"COMMIT");
                            } 
                            catch (Exception $ex) {
        
                                var_dump($ex->getMessage());
        
                                // クエリのロールバック
                                pg_query($pg_conn,"ROLLBACK");
                                pg_close($pg_conn);
                            }
                    break;

                     
                    /// <summery>
                    /// 予約情報が重複して無いか確認(予約変更時)
                    /// </summery>
                    case 'CheckDoubleDataEdit':

                        // 保存させたいデータ (配列)
                        $SaveCheckDataArray = $array_data->SaveData;

                        try
                        {

                            // ✅ すべての重複データをまとめて格納する配列
                            $allDoubleData = [];

                                $CarId = $SaveCheckDataArray->CarId;
                                $ReserveId = $SaveCheckDataArray->ReserveId;
                                $UseStartDay = $SaveCheckDataArray->StartDate;
                                $StartTime = $SaveCheckDataArray->StartTime;
                                $UseEndDay = $SaveCheckDataArray->EndDate;
                                $UseEndTime = $SaveCheckDataArray->EndTime;


                                // マスター情報取得クエリ
                                $sql_1 = "
                                SELECT
                                    use_start_day,
                                    start_time,
                                    end_time,
                                    driver
                                FROM reserve
                                WHERE
                                car_id = $1
                                AND reserve_id != $4
                                AND cancel_day IS NULL
                                AND (
                                    (use_start_day || ' ' || start_time) < $2
                                    AND
                                    (use_end_day || ' ' || end_time) > $3
                                )
                                ORDER BY use_start_day ASC, start_time ASC;";

                                // $1 = $CarId $2 = "$UseEndDay $UseEndTime" $3 = "$UseStartDay $StartTime"
                                $params = [
                                    (int)$CarId,
                                    "$UseEndDay $UseEndTime",
                                    "$UseStartDay $StartTime",
                                    (int)$ReserveId
                                ];

                                // 実行
                                $result1 = pg_query_params($pg_conn, $sql_1, $params);

                                $rows = pg_fetch_all($result1);

                                // ✅ 結果が false（0件）なら空配列としてスキップ、それ以外はマージ
                                if ($rows !== false) {
                                    $allDoubleData = array_merge($allDoubleData, $rows);
                                }
                            
        
                                //オブジェクト配列
                                $all_data = ['data' => ['CheckData' => $allDoubleData] ]; 
        
            
                                //クエリのコミット
                                pg_query($pg_conn,"COMMIT");
                            } 
                            catch (Exception $ex) {
        
                                var_dump($ex->getMessage());
        
                                // クエリのロールバック
                                pg_query($pg_conn,"ROLLBACK");
                                pg_close($pg_conn);
                            }
                    break;
                    
                    
                    // <summery>
                    // BookingGarage・マスター社有車情報入手
                    // </summery>
                    case 'GetMasterInfo':
 
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
                            $sql_1 = 'SELECT
                                a.car_id,
                                a.car_name,
                                a.car_no,
                                a.garages,
                                a.etc,
                                a.creat_day,
                                a.create_user_id,
                                a.seat_of_number,
                                a.use_display,
                                a.is_rental,
                                a.unlimited_day,
                                a.limited_day,
                                a.new_mileage,
                                b.user_name
                                FROM cars a
                                LEFT JOIN temp_user_table b ON a.create_user_id = b.user_id --一時的なユーザーテーブルと結合する
                                WHERE a.un_useble_day IS NULL
                                ORDER BY a.display_no ASC, a.car_id ASC;
                            ';
 
                            // 実行
                            $result1 = pg_query($sql_1);
                            $MasterBookingData = pg_fetch_all($result1);
 
                            //オブジェクト配列
                            $all_data = ['data' => ['MasterGarage' => $MasterBookingData] ];
 
       
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


                    // <summery>
                    // BookingGarage・マスター社有車情報入手
                    // </summery>
                    case 'GetOldMasterInfo':

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
                            $sql_1 = 'SELECT
                                a.car_id,
                                a.car_name,
                                a.car_no,
                                a.garages,
                                a.etc,
                                a.creat_day,
                                a.create_user_id,
                                a.seat_of_number,
                                a.use_display,
                                b.user_name
                                FROM cars a
                                LEFT JOIN temp_user_table b ON a.create_user_id = b.user_id --一時的なユーザーテーブルと結合する
                                WHERE a.un_useble_day IS NOT NULL
                                ORDER BY a.display_no ASC;
                            ';

                            // 実行
                            $result1 = pg_query($sql_1);
                            $MasterBookingData = pg_fetch_all($result1);

                            //オブジェクト配列
                            $all_data = ['data' => ['MasterGarage' => $MasterBookingData] ]; 

        
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

                    // <summery>
                    // BookingGarage・社有車予約情報入手)(OLD画面)
                    // </summery>
                    case 'GetCarCategory':

                        try
                        {
                            // マスター情報取得クエリ
                            $sql_1 = 'SELECT
                                car_id,car_name,car_no,garages,etc
                                FROM cars
                                WHERE un_useble_day IS NULL 
                                ORDER BY car_id ASC ;
                            ';

                            // 実行
                            $result1 = pg_query($sql_1);
                            $MasterBookingData = pg_fetch_all($result1);


                            // 予約情報入手
                            $sql_2 = 'SELECT 
                                a.reserve_id,
                                a.car_id,
                                a.use_start_day,
                                a.start_time,
                                a.use_end_day,
                                a.end_time,
                                a.driver,
                                a.place,
                                a.number_of_people,
                                a.luggage,
                                a.memo,
                                a.etc as reserve_etc,

                                b.car_name,
                                b.car_no,
                                b.garages,
                                b.etc

                                FROM reserve a

                                LEFT JOIN cars b
                                USING(car_id)
                                WHERE cancel_day IS NULL
                                ORDER BY a.start_time ASC;   
                            ';
                            
                            // 実行
                            $result2 = pg_query($sql_2);
                            $ReserveBookingData = pg_fetch_all($result2);

                            //オブジェクト配列
                            $all_data = ['data' => ['MasterGarage' => $MasterBookingData,'ReseveGarage' => $ReserveBookingData] ]; 

        
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

                    // <summery>
                    // マスター車情報入手(HistroySearch画面)
                    // </summery>
                    case 'GetMasterCarCategory':

                        try
                        {
                            
                            // マスター情報取得クエリ
                            $sql_1 = 'SELECT
                                car_id,
                                car_name,
                                car_no

                                FROM cars 

                                WHERE un_useble_day IS NULL
                                ORDER BY car_id ASC
                            ';
             

                            // 実行
                            $result1 = pg_query($sql_1);
                            $MasterData = pg_fetch_all($result1);

                            $sql_2 = 'SELECT
                            car_id,
                            car_name,
                            car_no

                            FROM cars 

                            WHERE un_useble_day IS NOT NULL
                            ORDER BY car_id ASC
                        ';

                            // 実行
                            $result2 = pg_query($sql_2);
                            $DELETEMasterData = pg_fetch_all($result2);

                            //オブジェクト配列
                            $all_data = ['data' => ['MasterGarage' => $MasterData, 'DELETEMasterGarage' => $DELETEMasterData] ]; 

        
                            //クエリのコミット
                            pg_query($pg_conn,"COMMIT");
    
                        } 
                        catch (Exception $ex) {
    
                            var_dump($ex);
    
                            // クエリのロールバック
                            pg_query($pg_conn,"ROLLBACK");
                            pg_close($pg_conn);
    
                        }

                    break;

                    // <summery>
                    // 過去の予約履歴確認(BookingHistroySearch画面)
                    // </summery>
                    case 'GetHistoryReserveInfo':

                        $SelectCheckDataArray = $array_data->SerchHistory;

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
 


                            $ForBellow = $SelectCheckDataArray->ForBellow;
                            $PullCars = $SelectCheckDataArray->PullCars;
                            $CarId = $SelectCheckDataArray->CarId;
                            $StartDate = $SelectCheckDataArray->StartDate;
                            $EndDate = $SelectCheckDataArray->EndDate;
                            
                            $conditions = ["a.cancel_day IS NULL"]; // ベース条件
                            $params = []; // パラメータ配列
                            $paramIndex = 1;
                            
                            // ForBellowがtrueなら日付フィルタを追加
                            if ($ForBellow === false) {
                                $conditions[] = "a.use_start_day >= $" . $paramIndex++;
                                $params[] = $StartDate;
                            
                                $conditions[] = "a.use_start_day <= $" . $paramIndex++;
                                $params[] = $EndDate;
                            }
                            
                            // PullCarsが「全車種」以外なら車両フィルタを追加
                            if ($PullCars !== "全車種") {
                                $conditions[] = "b.car_id = $" . $paramIndex++;
                                $params[] = $CarId;
                            }
                            
                            // 条件を結合
                            $whereClause = implode(" AND ", $conditions);
                            
                            // SQLを組み立て
                            $sql_1 = "
                            SELECT
                                a.use_start_day,
                                a.start_time,
                                a.end_time,
                                a.driver,
                                a.place,
                                a.number_of_people,
                                a.luggage,
                                a.memo,
                                a.etc,
                                a.created_user_id,
                                a.created_day,
                                b.car_name,
                                b.car_no,
                                b.garages,
                                c.user_name
                            FROM reserve a
                            LEFT JOIN temp_user_table c ON a.created_user_id = c.user_id
                            LEFT JOIN cars b USING(car_id)
                            WHERE $whereClause
                            ORDER BY display_no ASC, b.car_id ASC, a.use_start_day ASC;
                            ";
                            
                            // 実行
                            if ($PullCars == "全車種" && $ForBellow === false) {
                                $result1 = pg_query_params($pg_conn, $sql_1, $params);
                            }
                            else{
                                $result1 = pg_query_params($pg_conn, $sql_1, $params);
                            }
                            $ReserveBookingData = pg_fetch_all($result1);


                            //オブジェクト配列
                            $all_data = ['data' => ['ReserveHistoryData' => $ReserveBookingData] ]; 

        
                            //クエリのコミット
                            pg_query($pg_conn,"COMMIT");
    
                        } 
                        catch (Exception $ex) {
    
                            var_dump($ex);
    
                            // クエリのロールバック
                            pg_query($pg_conn,"ROLLBACK");
                            pg_close($pg_conn);
    
                        }


                    break;

                    // <summery>
                    // 過去の予約履歴確認(BookingHistroySearch画面)
                    // </summery>
                    case 'GetMileageHistoryAllInfo':

                        $SelectCheckDataArray = $array_data->SerchHistory;

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
 


                            $ForBellow = $SelectCheckDataArray->ForBellow;
                            $PullCars = $SelectCheckDataArray->PullCars;
                            $CarId = $SelectCheckDataArray->CarId;
                            $StartDate = $SelectCheckDataArray->StartDate;
                            $EndDate = $SelectCheckDataArray->EndDate;
                            
                            $conditions = ["a.car_id IS NOT NULL"]; // ベース条件(この条件はSQLの為適当医に入れる)
                            $params = []; // パラメータ配列
                            $paramIndex = 1;
                            
                            // ForBellowがtrueなら日付フィルタを追加
                            if ($ForBellow === false) {
                                $conditions[] = "a.add_day >= $" . $paramIndex++;
                                $params[] = $StartDate;
                            
                                $conditions[] = "a.add_day <= $" . $paramIndex++;
                                $params[] = $EndDate;
                            }
                            
                            // PullCarsが「全車種」以外なら車両フィルタを追加
                            if ($PullCars !== "全車種") {
                                $conditions[] = "b.car_id = $" . $paramIndex++;
                                $params[] = $CarId;
                            }
                            
                            // 条件を結合
                            $whereClause = implode(" AND ", $conditions);
                            
                            // SQLを組み立て
                            $sql_1 = "
                            SELECT
                                a.mileage,
                                a.difference_mileage,
                                a.add_day,
                                b.car_name,
                                b.car_no,
                                b.garages,
                                c.user_name

                            FROM cars_mileage_history a
                            LEFT JOIN cars b USING(car_id)
                            LEFT JOIN temp_user_table c ON a.add_user_id = c.user_id
                            WHERE $whereClause
                            ORDER BY display_no ASC, b.car_id ASC;
                            ";
                            
                            // 実行
                            if ($PullCars == "全車種" && $ForBellow === false) {
                                $result1 = pg_query_params($pg_conn, $sql_1, $params);
                            }
                            else{
                                $result1 = pg_query_params($pg_conn, $sql_1, $params);
                            }
                            $HistoryMileageData = pg_fetch_all($result1);


                            //オブジェクト配列
                            $all_data = ['data' => ['HistoryMilageData' => $HistoryMileageData] ]; 

        
                            //クエリのコミット
                            pg_query($pg_conn,"COMMIT");
    
                        } 
                        catch (Exception $ex) {
    
                            var_dump($ex);
    
                            // クエリのロールバック
                            pg_query($pg_conn,"ROLLBACK");
                            pg_close($pg_conn);
    
                        }


                    break;

                    /// <summary>
                    /// 走行距離を取得する
                    /// </summary>
                    case 'GetMileageNewInfo':

                        try
                        {

                            // マスター情報取得クエリ
                            $sql_1 = 'SELECT
                                car_id,
                                car_name,
                                car_no,
                                garages,
                                new_mileage,
                                is_rental

                                FROM cars
                                WHERE un_useble_day IS NULL
                            ';
 
                            // 実行
                            $result1 = pg_query($sql_1);
                            $MasterBookingData = pg_fetch_all($result1);
 
                            //オブジェクト配列
                            $all_data = ['data' => ['MasterGarage' => $MasterBookingData] ];
 
       
                            //クエリのコミット
                            pg_query($pg_conn,"COMMIT");
   
    
                        } 
                        catch (Exception $ex) {
    
                            var_dump($ex);
    
                            // クエリのロールバック
                            pg_query($pg_conn,"ROLLBACK");
                            pg_close($pg_conn);
    
                        }


                    break;

                    /// <summary>
                    /// 現在のタイヤ情報を取得する
                    /// </summary>
                    case 'GetTiresInfo':

                        try
                        {

                            // マスター情報取得クエリ
                            $sql_1 = 'SELECT
                                a.car_id,
                                a.car_name,
                                a.car_no,
                                a.garages,
                                a.use_season_summer_tires,
                                a.is_rental,
                                b.tire_id,
                                b.tire_storage,
                                b.tire_size,
                                b.purchase_day,
                                b.use_season_summer


                                FROM cars a
                                LEFT JOIN cars_tires b ON a.car_id = b.car_id
                                WHERE un_useble_day IS NULL AND b.useble_change_day IS NULL
                            ';
 
                            // 実行
                            $result1 = pg_query($sql_1);
                            $MasterBookingData = pg_fetch_all($result1);
 
                            //オブジェクト配列
                            $all_data = ['data' => ['MasterGarage' => $MasterBookingData] ];
 
       
                            //クエリのコミット
                            pg_query($pg_conn,"COMMIT");
   
    
                        } 
                        catch (Exception $ex) {
    
                            var_dump($ex);
    
                            // クエリのロールバック
                            pg_query($pg_conn,"ROLLBACK");
                            pg_close($pg_conn);
    
                        }


                    break;

                    /// <sumeery>
                    /// タイヤ情報とマスター情報を取得する
                    /// </summary>
                    case 'GetTiresEditData':

                        try
                        {
                            
                            // マスター情報取得クエリ
                            $sql_1 = 'SELECT
                                car_id,
                                car_name,
                                car_no

                                FROM cars 

                                WHERE un_useble_day IS NULL
                                ORDER BY car_id ASC
                            ';
             

                            // 実行
                            $result1 = pg_query($sql_1);
                            $MasterData = pg_fetch_all($result1);

                            $sql_2 = 'SELECT
                            car_id,
                            tire_id,
                            use_season_summer,
                            tire_size,
                            tire_storage,
                            purchase_day,
                            memo


                            FROM cars_tires 

                            WHERE useble_change_day IS NULL
                            ORDER BY car_id ASC
                        ';

                            // 実行
                            $result2 = pg_query($sql_2);
                            $TireEditData = pg_fetch_all($result2);

                            //オブジェクト配列
                            $all_data = ['data' => ['MasterGarage' => $MasterData, 'TireData' => $TireEditData] ]; 

        
                            //クエリのコミット
                            pg_query($pg_conn,"COMMIT");
    
                        } 
                        catch (Exception $ex) {
    
                            var_dump($ex);
    
                            // クエリのロールバック
                            pg_query($pg_conn,"ROLLBACK");
                            pg_close($pg_conn);
    
                        }

                    break;

                    // <summery>
                    // スケジュール
                    // 予定取得
                    // </summery>
                    case 'GetSchedule':
                       
                        // 取得したい年度
                        $fiscal_year = $array_data->fiscal_year;

                        try
                        {
                           
                            //スケジュール取得クエリ
                            $sql = 'SELECT
                            schedule_id,
                            title_id,
                            date,
                            car_id,
                            fiscal_year,
                            memo
                            FROM schedule
                            WHERE fiscal_year = $1 AND delete_day IS NULL
                            ORDER BY car_id ASC';
 
                            // 実行（プレースホルダを使って安全に）
                            $result1 = pg_query_params($pg_conn, $sql, [$fiscal_year]);
 
                            $scheduleData = pg_fetch_all($result1);
 
                            //オブジェクト配列
                            $all_data = ['data' =>  $scheduleData];
 
       
                            //クエリのコミット
                            pg_query($pg_conn,"COMMIT");
                        }
                        catch (Exception $ex) {
   
                            var_dump($ex);
   
                            // クエリのロールバック
                            pg_query($pg_conn,"ROLLBACK");
                            pg_close($pg_conn);
   
                        }
 
                    break;

                    // <summery>
                    // スケジュール
                    // 予定タイトル取得
                    // </summery>
                    case 'GetScheduleTitle':
                        try
                        {
                           
                            //スケジュール取得クエリ
                            $sql = 'SELECT
                            title_id,
                            title_name,
                            title_color,
                            delete_day
                            FROM schedule_title
                            --WHERE delete_day IS NULL
                            ORDER BY title_id ASC';
 
                            // 実行（プレースホルダを使って安全に）
                            $result1 = pg_query($pg_conn, $sql);
 
                            $scheduleData = pg_fetch_all($result1);
 
                            //オブジェクト配列
                            $all_data = ['data' =>  $scheduleData];
 
       
                            //クエリのコミット
                            pg_query($pg_conn,"COMMIT");
                        }
                        catch (Exception $ex) {
   
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




    // フロントに返す //////////////////////////////////////////////////
	
    // 配列をJSONに変換
    $json_value = json_encode($all_data);

    // JSON形式で返信するためのヘッダー
    header("Content-Type: application/json; charset=utf-8");

	// JSONの書きだし
	print_r($json_value);

    ////////////////////////////////////////////////////////////////////




?>