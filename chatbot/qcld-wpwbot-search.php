<?php
/**
 * Product indexing, caching & searching features concept is taken from open source 'Advanced wp Search' Wp plugin by ILLID.
 */
//include_once( 'includes/class-wpwbot-cache.php' );

include_once( 'includes/class-wpwbot-table.php' );
include_once( 'includes/class-wpwbot-search.php' );

function wpbo_search_site() {
	
	$results = new WP_Query( array(
		'post_type'     => array( 'post', 'page' ),
		'post_status'   => 'publish',
		'nopaging'      => true,
		'posts_per_page'=> 10,
		's'             => stripslashes( $_POST['keyword'] ),
	) );
	

	
	$response = array();
	$response['status'] = 'fail';
	
	if ( !empty( $results->posts ) ) {
		$response['status'] = 'success';
		$response['html'] = '<div class="wpb-search-result">';
		$response['html'] .=  esc_html_e('<p>We have found '.count($results->posts).' results for <b>'.$_POST['keyword'].'</b></p>.','wpbot');
		foreach ( $results->posts as $result ) {
			$response['html'] .= '<a href="'.$result->guid.'" target="_blank">'.$result->post_title.'</a>';
		}
		$response['html'] .='</div>';
	}else{
		$texts = unserialize(get_option('qlcd_wp_chatbot_no_result'));
		$response['html'] = esc_html($texts[array_rand($texts)]);
	}
	
	echo json_encode($response);

	die();
}

add_action( 'wp_ajax_wpbo_search_site',        'wpbo_search_site' );
add_action( 'wp_ajax_nopriv_wpbo_search_site', 'wpbo_search_site' );

add_action( 'wp_ajax_wpbo_search_responseby_intent',        'qc_wpbo_search_responseby_intent' );
add_action( 'wp_ajax_nopriv_wpbo_search_responseby_intent', 'qc_wpbo_search_responseby_intent' );

function qc_wpbo_search_responseby_intent(){
	global $wpdb;
	$keyword = sanitize_text_field($_POST['keyword']);
	$table = $wpdb->prefix.'wpbot_response';

	$result = $wpdb->get_row("SELECT `response` FROM `$table` WHERE 1 and `intent` = '".$keyword."'");
	
	$response = array('status'=>'fail');
	
	if(!empty($result)){
		$response['status'] = 'success';
		$response['html'] = $result->response;
	}

	echo json_encode($response);

	die();

}

add_action( 'wp_ajax_wpbo_search_response_catlist',        'wpbo_search_response_catlist' );
add_action( 'wp_ajax_nopriv_wpbo_search_response_catlist', 'wpbo_search_response_catlist' );

function wpbo_search_response_catlist(){
	global $wpdb;
	$table = $wpdb->prefix.'wpbot_response_category';
	
	$status = array('status'=>'fail');
	$results = $wpdb->get_results("SELECT * FROM `$table` WHERE 1");
	$response_result = array();
	
	if(!empty($results)){
		foreach($results as $result){
			
			$response_result[] = array('name'=>$result->name);
			
		}
	}
	
	if(!empty($response_result)){

		$status = array('status'=>'success', 'data'=>$response_result);
		

	}
	
	echo json_encode($status);

	die();
	
}

add_action( 'wp_ajax_wpbo_search_response',        'qc_wpbo_search_response' );
add_action( 'wp_ajax_nopriv_wpbo_search_response', 'qc_wpbo_search_response' );



function qc_wpbo_search_response(){
	global $wpdb;
	$keyword = (sanitize_text_field($_POST['keyword']));
	$strid = (sanitize_text_field($_POST['strid']));
	$table = $wpdb->prefix.'wpbot_response';
	

	$response_result = array();

	$status = array('status'=>'fail', 'multiple'=>false);
	if(($strid != '') && empty($response_result)){
		$results = $wpdb->get_results("SELECT * FROM `$table` WHERE `ID` = ".$strid);	
		if(!empty($results)){
			foreach($results as $result){
				
				$response_result[] = array('id'=>$result->id,'query'=>$result->query, 'response'=>$result->response, 'score'=>1);
				
			}
		}
	}
	
	$results = $wpdb->get_results("SELECT `id`, `query`, `response` FROM `$table` WHERE 1 and `query` = '".$keyword."'");
	
	if(!empty($results)){
		foreach($results as $result){
			
			$response_result[] = array('id'=>$result->id,'query'=>$result->query, 'response'=>$result->response, 'score'=>1);
			
		}
	}
	if(empty($response_result)){
		$results = $wpdb->get_results("SELECT `id`, `query`, `response` FROM `$table` WHERE 1 and `category` = '".$keyword."'");
		
		
		if(!empty($results)){
			foreach($results as $result){
				$response_result[] = array('id'=>$result->id,'query'=>$result->query, 'response'=>$result->response, 'score'=>1);
			}
			if(count($response_result)>1){
				$status = array('status'=>'success','category'=> true, 'multiple'=>true, 'data'=>$response_result);
			}else{
				$status = array('status'=>'success', 'category'=> true, 'multiple'=>false, 'data'=>$response_result);
			}
			
			echo json_encode($status);

			die();
		}
		
	}
	
	if(class_exists('Qcld_str_pro')){
		if(get_option('qc_bot_str_remove_stopwords') && get_option('qc_bot_str_remove_stopwords')==1){
			$keyword = qc_strpro_remove_stopwords($keyword);
		}
	}
	
	
	if(empty($response_result)){

		$fields = get_option('qc_bot_str_fields');
		if($fields && !empty($fields)){
			$qfields = implode(', ', $fields);
		}else{
			$qfields = '`query`,`keyword`,`response`';
		}
		$sql = "ALTER TABLE `{$table}` ADD FULLTEXT($qfields);";
		$wpdb->query( $sql );
		$sql_text = "SELECT `id`, `query`, `response`, MATCH($qfields) AGAINST('".$keyword."' IN NATURAL LANGUAGE MODE) as score FROM $table WHERE MATCH($qfields) AGAINST('".$keyword."' IN NATURAL LANGUAGE MODE) order by score desc limit 15";
		$results = $wpdb->get_results($sql_text);
		
		$weight = get_option('qc_bot_str_weight')!=''?get_option('qc_bot_str_weight'):'0.4';
		if(!empty($results)){
			foreach($results as $result){
				if(($result->score) >= ($weight)){
					$response_result[] = array('id'=>$result->id,'query'=>$result->query, 'response'=>$result->response, 'score'=>$result->score);
				}
			}
		}
	}

	if( empty( $response_result ) ){
		$results = $wpdb->get_results("SELECT * FROM `$table` WHERE `keyword` REGEXP '".$keyword."'");
		if(!empty($results)){
			foreach($results as $result){
				$response_result[] = array('id'=>$result->id,'query'=>$result->query, 'response'=>$result->response, 'score'=>1);
			}
		}
	}
	if(!empty($response_result)){
		
		if(count($response_result)>1){
			$status = array('status'=>'success', 'multiple'=>true, 'data'=>$response_result);
		}else{
			$status = array('status'=>'success', 'multiple'=>false, 'data'=>$response_result);
		}

	}
	if(empty($result->query)){
		$status = array('status'=>'fail', 'multiple'=>false, 'data'=>$response_result);
	}
	echo json_encode($status);

	die();

}

function qc_strpro_remove_stopwords($keyword){
	
	if(get_option('qlcd_wp_chatbot_stop_words') && get_option('qlcd_wp_chatbot_stop_words')!=''){
		$commonWords = explode(',', get_option('qlcd_wp_chatbot_stop_words'));
		return preg_replace('/\b('.implode('|',$commonWords).')\b/','',$keyword);
	}else{
		return $keyword;
	}
	
 
	
}
