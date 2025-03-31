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

                                WHERE a.use_display = true AND a.un_useble_day IS NULL
                                

                                ORDER BY display_car_id ASC, b.start_time ASC;
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
                                    (use_start_day || ' ' || start_time) <= $2
                                    AND
                                    (use_end_day || ' ' || end_time) >= $3
                                )
                                ORDER BY car_id ASC;";

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
                    case 'CheckDoubleData_Edit':

                        // 保存させたいデータ (配列)
                        $SaveCheckDataArray = $array_data->SaveData;

                        try
                        {

                            // ✅ すべての重複データをまとめて格納する配列
                            $allDoubleData = [];

                            // ループ処理で複数レコードを保存
                            foreach ($SaveCheckDataArray as $SaveData) {

                                $CarId = $SaveData->CarId;
                                $UseStartDay = $SaveData->StartDate;
                                $StartTime = $SaveData->StartTime;
                                $UseEndDay = $SaveData->EndDate;
                                $UseEndTime = $SaveData->EndTime;

                                var_dump($CarId, "$UseEndDay $UseEndTime", "$UseStartDay $StartTime");


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
                                    (use_start_day || ' ' || start_time) <= $2
                                    AND
                                    (use_end_day || ' ' || end_time) >= $3
                                )
                                ORDER BY car_id ASC;";

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
                     
                    
                    // <summery>
                    // BookingGarage・マスター社有車情報入手
                    // </summery>
                    case 'GetMasterInfo':

                        try
                        {
                            // マスター情報取得クエリ
                            $sql_1 = 'SELECT
                                car_id,car_name,car_no,garages,etc,creat_day,create_user_id,seat_of_number
                                FROM cars
                                WHERE un_useble_day IS NULL 
                                ORDER BY car_id ASC;
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
                            // マスター情報取得クエリ
                            $sql_1 = 'SELECT
                                car_id,car_name,car_no,garages,etc,un_useble_day,un_useble_user_id,seat_of_number
                                FROM cars
                                WHERE un_useble_day IS NOT NULL 
                                ORDER BY car_id ASC;
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