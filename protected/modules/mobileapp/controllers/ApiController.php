<?php
class ApiController extends CController
{	
	public $data;
	public $code=2;
	public $msg='';
	public $details='';
	
	public function __construct()
	{
		$this->data=$_GET;
		
		$website_timezone=Yii::app()->functions->getOptionAdmin("website_timezone");		 
	    if (!empty($website_timezone)){
	 	   Yii::app()->timeZone=$website_timezone;
	    }		 
	}
	
	public function beforeAction($action)
	{				
		/*check if there is api has key*/		
		$action=Yii::app()->controller->action->id;				
		if(isset($this->data['api_key'])){
			if(!empty($this->data['api_key'])){			   
			   $continue=true;
			   if($action=="getLanguageSettings" || $action=="registerMobile"){
			   	  $continue=false;
			   }
			   if($continue){
			   	   $key=getOptionA('mobileapp_api_has_key');
				   if(trim($key)!=trim($this->data['api_key'])){
				   	 $this->msg=$this->t("api hash key is not valid");
			         $this->output();
			         Yii::app()->end();
				   }
			   }			
			}
		}		
		return true;
	}	
	
	public function actionIndex(){
		//throw new CHttpException(404,'The specified url cannot be found.');
	}		
	
	private function q($data='')
	{
		return Yii::app()->db->quoteValue($data);
	}
	
	private function t($message='')
	{
		return Yii::t("default",$message);
	}
		
    private function output()
    {
	   $resp=array(
	     'code'=>$this->code,
	     'msg'=>$this->msg,
	     'details'=>$this->details,
	     'request'=>json_encode($this->data)		  
	   );		   
	   if (isset($this->data['debug'])){
	   	   dump($resp);
	   }
	   
	   if (!isset($_GET['callback'])){
  	   	   $_GET['callback']='';
	   }    
	   
	   if (isset($_GET['json']) && $_GET['json']==TRUE){
	   	   echo CJSON::encode($resp);
	   } else echo $_GET['callback'] . '('.CJSON::encode($resp).')';		    	   	   	  
	   Yii::app()->end();
    }	
	
	public function actionSearch()
	{		
		if (!isset($this->data['address'])){
			$this->msg=$this->t("Address is required");
			$this->output();
		}
		
		if (isset($_GET['debug'])){
			dump($this->data);
		}
		
		if ( !empty($this->data['address'])){
			 if ( $res_geo=Yii::app()->functions->geodecodeAddress($this->data['address'])){
			 	
			 	$home_search_unit_type=Yii::app()->functions->getOptionAdmin('home_search_unit_type');
			 	
			 	$home_search_radius=Yii::app()->functions->getOptionAdmin('home_search_radius');
			 	$home_search_radius=is_numeric($home_search_radius)?$home_search_radius:20;
			 	
			 	$lat=$res_geo['lat'];
				$long=$res_geo['long'];
				
				$distance_exp=3959;
				if ($home_search_unit_type=="km"){
					$distance_exp=6371;
				}		
				
				$DbExt=new DbExt; 
				$DbExt->qry("SET SQL_BIG_SELECTS=1");
				
				$lat=!empty($lat)?$lat:0;
				$long=!empty($long)?$long:0;				
			 	
				$total_records=0;
				$data='';
				
				$and="AND status='active' AND is_ready='2' ";
				
				$services_filter='';
				if (isset($this->data['services'])){
					$services=!empty($this->data['services'])?explode(",",$this->data['services']):false;					
					if ($services!=false){
						foreach ($services as $services_val) {
							if(!empty($services_val)){
							   $services_filter.="'$services_val',";
							}
						}
						$services_filter=substr($services_filter,0,-1);
						if(!empty($services_filter)){
						   $and.=" AND service IN ($services_filter)";
						}
					}
				}
				
				$filter_cuisine='';
				if (isset($this->data['cuisine_type'])){
					$cuisine_type=!empty($this->data['cuisine_type'])?explode(",",$this->data['cuisine_type']):false;
					if ($cuisine_type!=false){
						$x=1;
						foreach (array_filter($cuisine_type) as $cuisine_type_val) {							
							if ( $x==1){
							   $filter_cuisine.=" LIKE '%\"$cuisine_type_val\"%'";
						    } else $filter_cuisine.=" OR cuisine LIKE '%\"$cuisine_type_val\"%'";
							$x++;
					    }			
					    if (!empty($filter_cuisine)){
				           $and.=" AND (cuisine $filter_cuisine)";
				         }			
					}
				}
				
				
			 	$stmt="
				SELECT SQL_CALC_FOUND_ROWS a.*, ( $distance_exp * acos( cos( radians($lat) ) * cos( radians( latitude ) ) 
				* cos( radians( lontitude ) - radians($long) ) 
				+ sin( radians($lat) ) * sin( radians( latitude ) ) ) ) 
				AS distance								
				
				FROM {{view_merchant}} a 
				HAVING distance < $home_search_radius				
				$and ORDER BY is_sponsored DESC, distance ASC
				LIMIT 0,100
				";
			 	if (isset($_GET['debug'])){
			 	   dump($stmt);	
			 	}
			 	if ( $res=$DbExt->rst($stmt)){		
			 		
			 		$stmtc="SELECT FOUND_ROWS() as total_records";
			 		if ($resp=$DbExt->rst($stmtc)){			 			
			 			$total_records=$resp[0]['total_records'];
			 		}			 		
			 			 		
			 		$this->code=1;
			 		$this->msg=$this->t("Successful");
			 		
			 		foreach ($res as $val) {		

			 			$minimum_order=getOption($val['merchant_id'],'merchant_minimum_order');
			 			if(!empty($minimum_order)){
				 			$minimum_order=displayPrice(getCurrencyCode(),prettyFormat($minimum_order));		 			
			 			}
			 			
			 			$delivery_fee=getOption($val['merchant_id'],'merchant_delivery_charges');
			 			if (!empty($delivery_fee)){
			 				$delivery_fee=displayPrice(getCurrencyCode(),prettyFormat($delivery_fee));
			 			}
			 			
			 			/*check if mechant is open*/
			 			$open=AddonMobileApp::isMerchantOpen($val['merchant_id']);
			 			
				        /*check if merchant is commission*/
				        $cod=AddonMobileApp::isCashAvailable($val['merchant_id']);
				        $online_payment='';
				        
				        $tag='';
				        $tag_raw='';
				        if ($open==true){				        	
				        	$tag=$this->t("open");
				        	$tag_raw='open';
				        	if ( getOption( $val['merchant_id'] ,'merchant_close_store')=="yes"){
				        		$tag=$this->t("close");
				        		$tag_raw='close';
				        		if (getOption( $val['merchant_id'] ,'merchant_preorder')==1){
					        		$tag=$this->t("pre-order");
					        		$tag_raw='pre-order';
					        	}
				        	}
				        } else  {
				        	$tag=$this->t("close");
				        	$tag_raw='close';
				        	if (getOption( $val['merchant_id'] ,'merchant_preorder')==1){
				        		$tag=$this->t("pre-order");
				        		$tag_raw='pre-order';
				        	}
				        }			 		
				        
			 			
			 			$data[]=array(
			 			  'merchant_id'=>$val['merchant_id'],
			 			  'restaurant_name'=>$val['restaurant_name'],
			 			  'address'=>$val['street']." ".$val['city']." ".$val['state']." ".$val['post_code'],
			 			  'ratings'=>Yii::app()->functions->getRatings($val['merchant_id']),
			 			  'cuisine'=>AddonMobileApp::prettyCuisineList($val['cuisine']),
			 			  'delivery_fee'=>$delivery_fee,			 			  
			 			  'minimum_order'=>$minimum_order,
			 			  'delivery_est'=>getOption($val['merchant_id'],'merchant_delivery_estimation'),
			 			  'is_open'=>$tag,
			 			  'tag_raw'=>$tag_raw,
			 			  'payment_options'=>array(
			 			    'cod'=>$cod,
			 			    'online'=>$online_payment
			 			  ),			 			 
			 			  'logo'=>AddonMobileApp::getMerchantLogo($val['merchant_id']),
			 			  'offers'=>AddonMobileApp::getMerchantOffers($val['merchant_id'])
			 			);
			 		}			 		
			 					 		
			 		$this->details=array(
			 		  'total'=>$total_records,
			 		  'data'=>$data
			 		);
			 		
			 	} else $this->msg=$this->t("No restaurant found");
			 } else $this->msg=$this->t("Error has occured failed geocoding address");
		} else $this->msg=$this->t("Address is required");
		$this->output();
	}
	
	public function actionMenuCategory()
	{	
		$data='';	
		if (!isset($this->data['merchant_id'])){
			$this->msg=$this->t("Merchant id is missing");
			$this->output();
		}
		if ( $data = AddonMobileApp::merchantInformation($this->data['merchant_id'])){				
 			if($data['menu_category']=Yii::app()->functions->getCategoryList2($this->data['merchant_id'])){
 			  $data['has_menu_category']=2;
 			} else $data['has_menu_category']=1;
 			 			
 			$trans=getOptionA('enabled_multiple_translation'); 			
 			if ( $trans==2 && isset($_GET['lang_id'])){
 				$new='';
	 			if (AddonMobileApp::isArray($data['menu_category'])){
	 				foreach ($data['menu_category'] as $val) {	 					
	 					$val['category_name']=AddonMobileApp::translateItem('category',
	 					$val['category_name'],$val['cat_id']);
	 					$new[]=$val;
	 				}
	 				
	 				unset($data['menu_category']);
	 				$data['menu_category']=$new;
	 			}			 			
 			} 			
 			
 			$this->code=1;
			$this->msg=$this->t("Successful");			
			$this->details=$data;			
		} else $this->msg=$this->t("Restaurant not found");
				
		$this->output();
	}
	
	public function actionCuisineList()
	{
		if ($resp=Yii::app()->functions->Cuisine(true)){
			$this->code=1;
			$this->msg=$this->t("Successful");
			$this->details=$resp;
		} else $this->msg=$this->t("No cuisine found");
		$this->output();
	}
	
	public function actionGetItemByCategory()
	{				
		if (!isset($this->data['cat_id'])){
			$this->msg=$this->t("Category is is missing");
			$this->output();
		}
		if (!isset($this->data['merchant_id'])){
			$this->msg=$this->t("Merchant Id is is missing");
			$this->output();
		}
		
		$disabled_ordering=getOption($this->data['merchant_id'],'merchant_disabled_ordering');		
		
		if ($res=Yii::app()->functions->getItemByCategory($this->data['cat_id'],false,$this->data['merchant_id'])){
			
			$item='';
			foreach ($res as $val) {		
				//dump($val);
				$price='';	
				if (is_array($val['prices'])  && count($val['prices'])>=1){
					foreach ($val['prices'] as $val_price) {
						$val_price['price_pretty']=displayPrice(getCurrencyCode(),prettyFormat($val_price['price']));
						if ($val['discount']>0){
						    $val_price['price_discount']=$val_price['price']-$val['discount'];
						    $val_price['price_discount_pretty']=
						    AddonMobileApp::prettyPrice($val_price['price']-$val['discount']);
						}					
						$price[]=$val_price;
					}
				}						
							
				$trans=getOptionA('enabled_multiple_translation'); 
				if ( $trans==2 && isset($_GET['lang_id'])){
					$item[]=array(
					  'item_id'=>$val['item_id'],
					  
					  'item_name'=>AddonMobileApp::translateItem('item',$val['item_name'],
					  $val['item_id'],'item_name_trans'),
					  
					  'item_description'=>AddonMobileApp::translateItem('item',$val['item_description'],
					  $val['item_id'],'item_description_trans'),
					  
					  'discount'=>$val['discount'],
					  'photo'=>AddonMobileApp::getImage($val['photo']),
					  'spicydish'=>$val['spicydish'],
					  'dish'=>$val['dish'],
					  'single_item'=>$val['single_item'],
					  'single_details'=>$val['single_details'],
					  'not_available'=>$val['not_available'],
					  'prices'=>$price
					);
				} else {
					$item[]=array(
					  'item_id'=>$val['item_id'],
					  'item_name'=>$val['item_name'],
					  'item_description'=>$val['item_description'],
					  'discount'=>$val['discount'],
					  'photo'=>AddonMobileApp::getImage($val['photo']),
					  'spicydish'=>$val['spicydish'],
					  'dish'=>$val['dish'],
					  'single_item'=>$val['single_item'],
					  'single_details'=>$val['single_details'],
					  'not_available'=>$val['not_available'],
					  'prices'=>$price
					);
				}
			}
			/*dump($item);
			die();*/
									
			$this->code=1;
			$this->msg=$this->t("Successful");						
			$merchant_info= AddonMobileApp::merchantInformation($this->data['merchant_id']);
			$category_info=Yii::app()->functions->getCategory($this->data['cat_id']);			
			$this->details=array(
			   'disabled_ordering'=>$disabled_ordering=="yes"?2:1,
			  'image_path'=>websiteUrl()."/upload",
			  'default_item_pic'=>'mobile-default-logo.png',
			  'merchant_info'=>$merchant_info,
			  'category_info'=>$category_info,
			  'item'=>$item
			);
		} else {
			$this->msg=t("No food item found");
			$category_info=Yii::app()->functions->getCategory($this->data['cat_id']);
			$merchant_info= AddonMobileApp::merchantInformation($this->data['merchant_id']);
			$this->details=array(
			  'merchant_info'=>$merchant_info,
			  'category_info'=>$category_info,
			);
		}
		$this->output();
	}
	
	public function actionGetItemDetails()
	{		
		if (!isset($this->data['item_id'])){
			$this->msg=$this->t("Item id is missing");
			$this->output();
		}
		if (!isset($this->data['merchant_id'])){
			$this->msg=$this->t("Merchant Id is is missing");
			$this->output();
		}
		if ( $res=Yii::app()->functions->getItemById($this->data['item_id'])){			
			$data=$res[0];			
			$data['photo']=AddonMobileApp::getImage($data['photo']);
			$data['has_gallery']=1;

			if (!empty($data['item_description'])){
			   $data['item_description']=strip_tags($data['item_description']);		
			}
			
			$trans=getOptionA('enabled_multiple_translation'); 
			$lang_id=$_GET['lang_id'];
            if ( $trans==2 && isset($_GET['lang_id'])){                    
				if (AddonMobileApp::isArray($data['cooking_ref'])){					
					$new_cook='';
					foreach ($data['cooking_ref'] as $cok_id=>$cok_val) {						
						$new_cook[$cok_id]=AddonMobileApp::translateItem('cookingref',
						$cok_val,$cok_id,'cooking_name_trans');
					}
					unset($data['cooking_ref']);
					$data['cooking_ref']=$new_cook;
				}
				
				if (AddonMobileApp::isArray($data['ingredients'])){
					$new_ing='';
					foreach ($data['ingredients'] as $ing_id=>$ing_val) {
						$new_ing[$ing_id]=AddonMobileApp::translateItem('ingredients',
						$ing_val,$ing_id,'ingredients_name_trans');
					}
					unset($data['ingredients']);
					$data['ingredients']=$new_ing;
				}            
            }
			
            /*dump($data);
            die();*/
			
			//$trans=getOptionA('enabled_multiple_translation'); 
            if ( $trans==2 && isset($_GET['lang_id'])){			
            	if ( array_key_exists($_GET['lang_id'],(array)$data['item_name_trans'])){
            		if (!empty($data['item_name_trans'][$_GET['lang_id']])){
            			$data['item_name']=$data['item_name_trans'][$_GET['lang_id']];
            		}            	
            	}              	
            	if ( array_key_exists($_GET['lang_id'],(array)$data['item_description_trans'])){
            		if (!empty($data['item_description_trans'][$_GET['lang_id']])){
            			$data['item_description']=$data['item_description_trans'][$_GET['lang_id']];
            		}            	
            	}            
            }
			//die();
			
			if (is_array($data['prices']) && count($data['prices'])){
				$data['has_price']=2;		
				$price='';		
				foreach ($data['prices'] as $p) {	
					$discounted_price=$p['price'];
					if ($data['discount']>0){
						$discounted_price=$discounted_price-$data['discount'];
					}				
					
					//$trans=getOptionA('enabled_multiple_translation'); 
                    if ( $trans==2 && isset($_GET['lang_id'])){                    	
                    	$lang_id=$_GET['lang_id'];
                    	if (array_key_exists($lang_id,(array)$p['size_trans'])){
                    		if ( !empty($p['size_trans'][$lang_id]) ){
                    			$p['size']=$p['size_trans'][$lang_id];
                    		}                    	
                    	}                    
                    }					
					
					$price[]=array(
					  'price'=>$p['price'],
					  'pretty_price'=>displayPrice(getCurrencyCode(),prettyFormat($p['price'],$this->data['merchant_id'])),
					  'size'=>$p['size'],
					  'discounted_price'=>$discounted_price,
					  'discounted_price_pretty'=>AddonMobileApp::prettyPrice($discounted_price)
					);
				}
				$data['prices']=$price;
			} else $data['has_price']=1;
			
			
			if (is_array($data['addon_item']) && count($data['addon_item'])>=1){
				$addon_item='';					
				foreach ($data['addon_item'] as $val) {
					//unset($val['subcat_name_trans']);
					if ( $trans==2 && isset($_GET['lang_id'])){    						
						if (array_key_exists($lang_id,(array)$val['subcat_name_trans'])){
							if(!empty($val['subcat_name_trans'][$lang_id])){
								$val['subcat_name']=$val['subcat_name_trans'][$lang_id];
							}						
						}						
					}
					$sub_item='';
					if(is_array($val['sub_item']) && count($val['sub_item'])>=1){				       
					   foreach ($val['sub_item'] as $val2) {					   	
					   	   //unset($val2['sub_item_name_trans']);
					   	   //unset($val2['item_description_trans']);
					   	   $val2['pretty_price']=displayPrice(getCurrencyCode(),
					   	   prettyFormat($val2['price'],$this->data['merchant_id']));	
					   	   
					   	   /*check if price is numeric*/
					   	   if (!is_numeric($val2['price'])){
					   	   	   $val2['price']=0;
					   	   }
					   	   
					   	   if ( $trans==2 && isset($_GET['lang_id'])){  
					   	   	   if (array_key_exists($lang_id,(array)$val2['sub_item_name_trans'])){
					   	   	   	  if ( !empty($val2['sub_item_name_trans'][$lang_id]) ){
					   	   	   	  	 $val2['sub_item_name']=$val2['sub_item_name_trans'][$lang_id];
					   	   	   	  }					   	   	   
					   	   	   }					   	   
					   	   }
					   	   				   	   
					   	   $sub_item[]=$val2;
					   }					   
					}
					$val['sub_item']=$sub_item;
					$addon_item[]=$val;
				}			
				$data['addon_item']=$addon_item;
			}
			
			$gallery_list='';
			if (!empty($data['gallery_photo'])){
				$gallery_photo=json_decode($data['gallery_photo']);
				if(is_array($gallery_photo) && count($gallery_photo)>=1){
					foreach ($gallery_photo as $pic) {
						$gallery_list[]=AddonMobileApp::getImage($pic);
					}					
					$data['gallery_photo']=$gallery_list;
					$data['has_gallery']=2;
				}				
			}
			
			$data['currency_code']=Yii::app()->functions->adminCurrencyCode();
			$data['currency_symbol']=getCurrencyCode();
			$data['category_info']=Yii::app()->functions->getCategory($this->data['cat_id']);
						
			$this->code=1;
			$this->msg="OK";
			$this->details=$data;
		} else $this->msg=$this->t("Item not found");
		$this->output();
	}
	
	public function actionLoadCart()
	{				
		//dump($this->data);
		if (!isset($this->data['cart'])){
			$this->msg=$this->t("cart is missing");
			$this->output();
		}
		if (!isset($this->data['merchant_id'])){
			$this->msg=$this->t("Merchant Id is is missing");
			$this->output();
		}		
		if (!isset($this->data['search_address'])){
			$this->msg=$this->t("search address is is missing");
			$this->output();
		}
				
		if ($this->data['transaction_type']=="null" || empty($this->data['transaction_type'])){
			$this->data['transaction_type']="delivery";
		}
		
		if (!isset($this->data['delivery_date'])){
			$this->data['delivery_date']='';
		}	
		if ($this->data['delivery_date']=="null" || empty($this->data['delivery_date'])){
			$this->data['delivery_date']=date("Y-m-d");
		}
		
						
		$mtid=$this->data['merchant_id'];		
	    $merchant_info= AddonMobileApp::merchantInformation($mtid);							
	    
	    /*check services offers is pickup only*/
	    if (is_array($merchant_info) && count($merchant_info)>=1){
	    	if($merchant_info['service']==3){
	    		$this->data['transaction_type']="pickup";
	    	}
	    }
	    
		$cart_content='';
		$subtotal=0;
		$taxable_total=0;
		
		Yii::app()->functions->data="list";
		$subcat_list=Yii::app()->functions->getSubcategory2($mtid);		
		
		$item_total=0;
		
		/*pts*/
		$points=0;
		$has_pts=1;
		if (AddonMobileApp::hasModuleAddon('pointsprogram')){
			if (getOptionA('points_enabled')==1){
			   $has_pts=2;
			}
		}
		
		if(!empty($this->data['cart'])){			
			$cart=json_decode($this->data['cart'],true);
			//dump($cart);
			if (is_array($cart) && count($cart)>=1){
			    foreach ($cart as $val) {
			    	
			    	/*loyalty points pts*/
			    	if($has_pts==2){
			    		$set_price=explode("|",$val['price']);
			    		if(is_array($set_price) && count($set_price)>=1){
			    			$set_price=$set_price[0];
			    		} else $set_price=0;
			    		
			    		$set_price=($val['qty']*$set_price);
			    		$points+=PointsProgram::getPointsByItem($val['item_id'],$set_price);
			    	}
			    	
			    	/*group sub item*/
			    	$new_sub='';
			    	if (AddonMobileApp::isArray($val['sub_item'])){
			    		foreach ($val['sub_item'] as $valsubs) {			    			
			    			$new_sub[$valsubs['subcat_id']][]=array( 
			    			  'value'=>$valsubs['value'],
			    			  'qty'=>$valsubs['qty']
			    			);
			    		}
			    		$val['sub_item']=$new_sub;
			    	}		
			    				    				    				   
			    	$item_price=0;
			    	$item_size='';
			    	$temp_price=explode("|",$val['price']);			    	
			    	if (AddonMobileApp::isArray($temp_price)){
			    		$item_price=isset($temp_price[0])?$temp_price[0]:'';
			    		$item_size=isset($temp_price[1])?$temp_price[1]:'';
			    	}			    
			    		    	
			    	$food=Yii::app()->functions->getFoodItem($val['item_id']);			    	
			    	
			    	/*check if item qty is less than 1*/
			    	if($val['qty']<1){
			    		$val['qty']=1;
			    	}			    
			    				    				    
			    	$discounted_price=0;
			    	if ($val['discount']>0){
			    		$discounted_price=$item_price-$val['discount'];
			    		$subtotal+=($val['qty']*$discounted_price);
			    	} else {
			    		$subtotal+=($val['qty']*$item_price);
			    	}			    
			    				  		
			    	if ( $food['non_taxable']==1){	    
			      	   $taxable_total=$subtotal;
			    	}
			    	
			    	$item_total+=$val['qty'];
			    				    	
			    	$sub_item='';
			    	if(is_array($val['sub_item']) && count($val['sub_item'])>=1){
			    		foreach ($val['sub_item'] as $sub_cat_id=> $valsub0) {			    			
			    			foreach ($valsub0 as $valsub) {				    				
				    			if(!empty($valsub['value'])){
				    				$sub=explode("|",$valsub['value']);
				    				
				    				if ( $valsub['qty']=="itemqty"){
				    				   $qty=$val['qty'];
				    				} else {
				    					$qty=$valsub['qty'];
				    					if ($qty<1){
				    						$qty=1;
				    						$valsub['qty']=1;
				    					}				    				
				    				}				    			
				    				
				    				$subitem_total=($qty*$sub[1]);
				    				$subtotal+=$subitem_total;
				    				if ( $food['non_taxable']==1){	
				    				   $taxable_total+=$subitem_total;
				    				}
				    								    				
				    				$category_name='';
				    				if(array_key_exists($sub_cat_id,(array)$subcat_list)){
				    					$category_name=$subcat_list[$sub_cat_id];
				    				}			    			
				    				
				    				$sub_item[$category_name][]=array(
				    				  'subcat_id'=>$sub_cat_id,
				    				  'category_name'=>$category_name,
				    				  'sub_item_id'=>$sub[0],
				    				  'price'=>$sub[1],
				    				  'price_pretty'=>AddonMobileApp::prettyPrice($sub[1]),
				    				  'qty'=>$valsub['qty'],
				    				  'total'=>$subitem_total,
				    				  'total_pretty'=>AddonMobileApp::prettyPrice($subitem_total),
				    				  'sub_item_name'=>$sub[2]				    				  
				    				);
				    			}
			    			}
			    		}
			    	}
			    	
			    	$cooking_ref='';
			    	if (AddonMobileApp::isArray($val['cooking_ref'])){
			    		foreach ($val['cooking_ref'] as $valcook) {
			    			$cooking_ref[]=$valcook['value'];
			    		}
			    	}
			    	
			    	$ingredients='';			    	
			    	if (AddonMobileApp::isArray($val['ingredients'])){
			    		foreach ($val['ingredients'] as $valing) {
			    			$ingredients[]=$valing['value'];
			    		}
			    	}
			    	
			    	$cooking_ref='';
			    	if(AddonMobileApp::isArray($val['cooking_ref'])){
			    		$cooking_ref=$val['cooking_ref'][0]['value'];
			    	}
			    	$ingredients='';
			    	if(AddonMobileApp::isArray($val['ingredients'])){
			    		foreach ($val['ingredients'] as $val_ing) {
			    			$ingredients[]=$val_ing['value'];
			    		}
			    	}			    
			    	
			    	$discount_amt=0;
			    	if (isset($val['discount'])){
			    		$discount_amt=$val['discount'];
			    	}
			    	
			    	$cart_content[]=array(
			    	  'item_id'=>$val['item_id'],
			    	  'item_name'=>$food['item_name'],
			    	  'item_description'=>$food['item_description'],
			    	  'qty'=>$val['qty'],
			    	  'price'=>$item_price,
			    	  'price_pretty'=>AddonMobileApp::prettyPrice($item_price),
			    	  'total'=>$val['qty']*($item_price-$discount_amt),
			    	  'total_pretty'=>AddonMobileApp::prettyPrice($val['qty']* ($item_price-$discount_amt) ),
			    	  'size'=>$item_size,			
			    	  'discount'=>isset($val['discount'])?$val['discount']:'',
			    	  'discounted_price'=>$discounted_price,
			    	  'discounted_price_pretty'=>AddonMobileApp::prettyPrice($discounted_price),
			    	  'cooking_ref'=>$cooking_ref,
			    	  'ingredients'=>$ingredients,
			    	  'order_notes'=>$val['order_notes'],
			    	  'sub_item'=>$sub_item
			    	);
			    	
			    } /*end foreach*/
			    
			    			    
			    $ok_distance=2;
			    $delivery_charges=0;
			    $distance='';
			    
			    if ( $this->data['transaction_type']=="delivery"){			
			    	/*if($distance=AddonMobileApp::getDistance($mtid,$this->data['search_address'])){				    	  
			    	  $mt_delivery_miles=Yii::app()->functions->getOption("merchant_delivery_miles",$mtid); 	
			    	  if($mt_delivery_miles>0){
			    	  	 if ($distance['unit']!="ft"){		
				    	  	 if ($mt_delivery_miles<=$distance['distance']){
				    	  	 	$ok_distance=1;
				    	  	 }
			    	  	 }
			    	  }
			    	  			    		
					  if($res_delivery=AddonMobileApp::getDeliveryCharges($mtid,$distance['unit'],$distance['distance'])){
						 $delivery_charges=$res_delivery['delivery_fee'];										
					  }
			    	}*/

			    	if($distance_new=AddonMobileApp::getDistanceNew($merchant_info,$this->data['search_address'])){			    	   
			    	   $distance=array(
			    	     'unit'=>$distance_new['distance_type'],
			    	     'distance'=>$distance_new['distance'],
			    	   );
			    	   $delivery_charges=$distance_new['delivery_fee'];
			    	   
			    	   $merchant_delivery_distance=''; 
			    	   if($distance_new['distance_type_raw']=="ft" || $distance_new['distance_type_raw']=="millimeter"){
			    	   	 // do nothing
			    	   } else {			    	   	 			    	   	
			    	   	  if(is_numeric($merchant_delivery_distance)){
				    	   	  if ($merchant_delivery_distance<$distance_new['distance']){
					    	  	  $ok_distance=1;
					    	  }
			    	   	  }
			    	   }
			    	} else $ok_distance=1;
			    }
				
				$merchant_tax_percent=0;
				$merchant_tax=getOption($mtid,'merchant_tax');			
				
               /*get merchant offers*/
		    	$discount='';
		    	if ( $offer=Yii::app()->functions->getMerchantOffersActive($mtid)){			    		
		    		$merchant_spend_amount=$offer['offer_price'];
		        	$merchant_discount_amount=number_format($offer['offer_percentage'],0);			        	
		        	if ( $subtotal>=$merchant_spend_amount){
		        		$merchant_discount_amount1=$merchant_discount_amount/100;
		        		$discounted_amount=$subtotal*$merchant_discount_amount1;
		        		
		        		$subtotal-=$discounted_amount;
		        		if ( $food['non_taxable']==1){
		        		    $taxable_total-=$discounted_amount;
		        		}		        		
		        		$discount=array(
		        		  'amount'=>$discounted_amount,
		        		  'amount_pretty'=>AddonMobileApp::prettyPrice($discounted_amount),
		        		  'display'=>$this->t("Discount")." ".number_format($offer['offer_percentage'],0)."%"
		        		);
		        	}
		    	}
		    	
		    	/*check if has offer for free delivery*/
		    	$free_delivery_above_price=getOption($mtid,'free_delivery_above_price');
		        if(is_numeric($free_delivery_above_price)){
		        	if ($subtotal>=$free_delivery_above_price){
		        		$delivery_charges=0;
		        	}			        
		        }
		        
		        /*packaging*/		        
		        $merchant_packaging_charge=getOption($mtid,'merchant_packaging_charge');
		        if ($merchant_packaging_charge>0){
		        	if ( getOption($mtid,'merchant_packaging_increment')==2){		 		      		        		
		        		$merchant_packaging_charge=$merchant_packaging_charge*$item_total;
		        	}
		        } else $merchant_packaging_charge=0;
		        
	           /*get the tax*/
		        $tax=0;
		        if ( $merchant_tax>0){
		        	$merchant_tax_charges=getOption($mtid,'merchant_tax_charges');
		        	if ( $merchant_tax_charges==2){
		        		$tax=($taxable_total+$merchant_packaging_charge)*($merchant_tax/100);
		        	} else $tax=($taxable_total+$delivery_charges+$merchant_packaging_charge)*($merchant_tax/100);
		        }			    
		        		        			    
				$cart_final_content=array(
				  'cart'=>$cart_content,
				  'sub_total'=>array(
				    'amount'=>$subtotal,
				    'amount_pretty'=>AddonMobileApp::prettyPrice($subtotal)
				  )			      
				);				
								
				if (AddonMobileApp::isArray($discount)){
					$cart_final_content['discount']=$discount;
				}

				if ($delivery_charges>0){
					$cart_final_content['delivery_charges']=array(
					  'amount'=>$delivery_charges,
					  'amount_pretty'=>AddonMobileApp::prettyPrice($delivery_charges)
					);
				}
				if ($merchant_packaging_charge>0){
					$cart_final_content['packaging']=array(
					  'amount'=>$merchant_packaging_charge,
					  'amount_pretty'=>AddonMobileApp::prettyPrice($merchant_packaging_charge)
					);					
				}
				if ($tax>0){
					$cart_final_content['tax']=array(
					  'amount'=>AddonMobileApp::prettyPrice($tax),
					  'tax_pretty'=>self::t("Tax")." ".$merchant_tax."%",
					  'tax'=>unPrettyPrice($merchant_tax)
					);					
				}
				
				$grand_total=$subtotal+$delivery_charges+$merchant_packaging_charge+$tax;
				$cart_final_content['grand_total']=array(
				  'amount'=>$grand_total,
				  'amount_pretty'=>AddonMobileApp::prettyPrice($grand_total)
				);
				
				/*validation*/																
				$validation_msg='';
								
				if ( $this->data['transaction_type']=="delivery"){
				if ($ok_distance==1000){
					$distanceOption=Yii::app()->functions->distanceOption();
					$validation_msg=t("Sorry but this merchant delivers only with in ").
					getOption($mtid,'merchant_delivery_miles')." ".$distanceOption[getOption($mtid,'merchant_distance_type')];
				}
				}
				
				if ( $this->data['transaction_type']=="delivery"){
					/*delivery*/
					$minimum_order=getOption($mtid,'merchant_minimum_order');
				    $maximum_order=getOption($mtid,'merchant_maximum_order');
				    if(is_numeric($minimum_order)){							    
				    	if ($subtotal<$minimum_order){
				    		$validation_msg=$this->t("Sorry but Minimum order is")." ".AddonMobileApp::prettyPrice($minimum_order);
				    	}				    
				    }
				    if(is_numeric($maximum_order)){				
				    	if ($subtotal>$maximum_order){
				    		$validation_msg=$this->t("Maximum Order is")." ".AddonMobileApp::prettyPrice($maximum_order);
				    	}				    
				    }				    				    
				} else {
					/*pickup*/
					$minimum_order_pickup=getOption($mtid,'merchant_minimum_order_pickup');
				    $maximum_order_pickup=getOption($mtid,'merchant_maximum_order_pickup');
				    if(is_numeric($minimum_order_pickup)){				    	
				    	if ($subtotal<$minimum_order_pickup){
				    		$validation_msg=$this->t("sorry but the minimum pickup order is")." ".
				    		AddonMobileApp::prettyPrice($minimum_order_pickup);
				    	}				    
				    }
				    if(is_numeric($maximum_order_pickup)){				    	
				    	if ($subtotal>$maximum_order_pickup){
				    		$validation_msg=$this->t("sorry but the maximum pickup order is")." ".
				    		AddonMobileApp::prettyPrice($maximum_order_pickup);
				    	}				    
				    }
				}				

				/*if(!$is_merchant_open = Yii::app()->functions->isMerchantOpen($mtid)){					
					$merchant_preorder=getOption($mtid,'merchant_preorder');
					if($merchant_preorder==1){
						$is_merchant_open=true;
					}				
				}			
				
				if (!$is_merchant_open){
					$validation_msg=$this->t("Sorry merchant is closed");
				}*/				
				
				$required_time=getOption( $mtid ,'merchant_required_delivery_time');
				$required_time=$required_time=="yes"?2:1;
				
				/*pts*/
				$points_label='';
				if ($has_pts==2){
				   $pts_label_earn=getOptionA('pts_label_earn');
				   if(empty($pts_label_earn)){
				   	   $pts_label_earn=$this->t("This order earned {points} points");
				   } 
				   $points_label=smarty('points',$points,$pts_label_earn);
				}
				
			    $this->code=1;
			    $this->msg="OK";
			    $this->details=array(		
			      /*'is_merchant_open'=>$is_merchant_open,
			      'merchant_preorder'=>$merchant_preorder,*/
			      'validation_msg'=>$validation_msg,
			      'merchant_info'=>$merchant_info,
			      'transaction_type'=>$this->data['transaction_type'],
			      'delivery_date'=>$this->data['delivery_date'],
			      'delivery_time'=>isset($this->data['delivery_time'])?$this->data['delivery_time']:'',
			      'required_time'=>$required_time,
			      'currency_symbol'=>getCurrencyCode(),
			      'cart'=>$cart_final_content,	
			      'has_pts'=>$has_pts,	      
			      'points'=>$points,
			      'points_label'=>$points_label
			    );			    			    
			    if (AddonMobileApp::isArray($distance)){
			    	$this->details['distance']=$distance;
			    }			    
			} else $this->msg=$this->t("cart is empty");
		} else $this->msg=$this->t("cart is empty");
		
		if($this->code==2){
			$this->details=array(
			  'cart_total'=>displayPrice(getCurrencyCode(),prettyFormat(0)),
			  'merchant_info'=>$merchant_info,			  
			);
		}		
		$this->output();
	}
	
	public function actionCheckOut()
	{
	
		if (!isset($this->data['merchant_id'])){
			$this->msg=$this->t("Merchant Id is is missing");
			$this->output();
		}		
		if (!isset($this->data['search_address'])){
			$this->msg=$this->t("search address is is missing");
			$this->output();
		}		
		if (empty($this->data['transaction_type'])){
			$this->msg=$this->t("transaction type is missing");
			$this->output();
		}	
		if (empty($this->data['delivery_date'])){
			$this->msg=$this->data['transaction_type']." ".$this->t("type is missing");
			$this->output();
		}		
		if (!empty($this->data['delivery_time'])){
   	       $this->data['delivery_time']=date("G:i", strtotime($this->data['delivery_time']));	       	      
   	    }
   	    
	   /**check if customer chooose past time */
       if ( isset($this->data['delivery_time'])){
       	  if(!empty($this->data['delivery_time'])){
       	  	 $time_1=date('Y-m-d g:i:s a');
       	  	 $time_2=$this->data['delivery_date']." ".$this->data['delivery_time'];
       	  	 $time_2=date("Y-m-d g:i:s a",strtotime($time_2));	       	  	        	  	 
       	  	 $time_diff=Yii::app()->functions->dateDifference($time_2,$time_1);	       	  	 
       	  	 if (is_array($time_diff) && count($time_diff)>=1){
       	  	     if ( $time_diff['hours']>0){	       	  	     	
	       	  	     $this->msg=t("Sorry but you have selected time that already past");
	       	  	     $this->output(); 	  	     	
       	  	     }	       	  	
       	  	 }	       	  
       	  }	       
       }		    

       $mtid=$this->data['merchant_id']; 	 
       
       $time=isset($this->data['delivery_time'])?$this->data['delivery_time']:'';	       
       $full_booking_time=$this->data['delivery_date']." ".$time;
	   $full_booking_day=strtolower(date("D",strtotime($full_booking_time)));			
	   $booking_time=date('h:i A',strtotime($full_booking_time));			
	   if (empty($time)){
	   	  $booking_time='';
	   }	    
	   	   	   	   
	   if ( !Yii::app()->functions->isMerchantOpenTimes($mtid,$full_booking_day,$booking_time)){	
			$date_close=date("F,d l Y h:ia",strtotime($full_booking_time));
			$date_close=Yii::app()->functions->translateDate($date_close);
			$this->msg=t("Sorry but we are closed on")." ".$date_close;
			$this->msg.="\n\t\n";
			$this->msg.=t("Please check merchant opening hours");
		    $this->output();
		}					 
			   
	   /*check if customer already login*/
	   $address_book='';
	   $next_step='checkoutSignup';
	   //if (!empty($this->data['client_token'])){
	   if ( $resp=AddonMobileApp::getClientTokenInfo($this->data['client_token'])) {
	   	  $next_step='shipping';
	   	  if ( $this->data['transaction_type']=="pickup" ){
	   	  	 $next_step='payment_method';
	   	  }	   	   	  
	   	  $address_book=AddonMobileApp::getAddressBook($resp['client_id']);	   	  
	   }	
	   
	   
	   
	   $this->code=1;
	   $this->msg=$address_book;
	   $this->details=$next_step;
	   $this->output();
	}
	
	public function actionSignup()
	{	
				
		$Validator=new Validator;
		$req=array(
		  'first_name'=>$this->t("first name is required"),
		  'last_name'=>$this->t("last name is required"),
		  'contact_phone'=>$this->t("contact phone is required"),
		  'email_address'=>$this->t("email address is required"),
		  'password'=>$this->t("password is required"),
		  'cpassword'=>$this->t("confirm password is required"),
		);
		
		if ($this->data['password']!=$this->data['cpassword']){
			$Validator->msg[]=$this->t("confirm password does not match");
		}	
		
		$Validator->required($req,$this->data);
		if ($Validator->validate()){
			
			/*check if email address is blocked*/
	    	if ( FunctionsK::emailBlockedCheck($this->data['email_address'])){
	    		$this->msg=$this->t("Sorry but your email address is blocked by website admin");
	    		$this->output();
	    	}	   
	    	if ( FunctionsK::mobileBlockedCheck($this->data['contact_phone'])){
	    		$this->msg=$this->t("Sorry but your mobile number is blocked by website admin");
	    		$this->output();
	    	}	    	
	    	/*check if mobile number already exist*/
	        $functionk=new FunctionsK();
	        if ( $functionk->CheckCustomerMobile($this->data['contact_phone'])){
	        	$this->msg=$this->t("Sorry but your mobile number is already exist in our records");
	        	$this->output();
	        }	  
	        if ( !$res=Yii::app()->functions->isClientExist($this->data['email_address']) ){
	        	
	        	$token=AddonMobileApp::generateUniqueToken(15,$this->data['email_address']);
	        	$params=array(
	    		  'first_name'=>$this->data['first_name'],
	    		  'last_name'=>$this->data['last_name'],
	    		  'email_address'=>$this->data['email_address'],
	    		  'password'=>md5($this->data['password']),
	    		  'date_created'=>date('c'),
	    		  'ip_address'=>$_SERVER['REMOTE_ADDR'],
	    		  'contact_phone'=>$this->data['contact_phone'],
	    		  'token'=>$token,
	    		  'social_strategy'=>"mobile"
	    		);	    	    	

	    		$is_checkout=1;
	    		    		
	    		if ($this->data['transaction_type']=="pickup"){
	    			$this->data['next_step']='payment_option';
	    		}		    		
	    		
	    		/*check if the form is checkout*/
	    		if(isset($this->data['transaction_type'])){
		    	   if ($this->data['transaction_type']=="pickup"){
	    			   $is_checkout='payment_option';
	    		   }		    		
	    		   if ($this->data['transaction_type']=="delivery"){
	    			   $is_checkout='shipping_address';
	    		   }		    		
	    		}
	    		
	    		/*check if verification is enabled mobile or web*/
	    		$website_enabled_mobile_verification=getOptionA('website_enabled_mobile_verification');
	    		$theme_enabled_email_verification=getOptionA('theme_enabled_email_verification');
	    		
	    		$verification_type='';
	    		if ($website_enabled_mobile_verification=="yes"){
	    			$verification_type='mobile_verification';
	    			$sms_code=Yii::app()->functions->generateRandomKey(5);
	    			$params['mobile_verification_code']=$sms_code;
	    			$params['status']='pending';
	    			Yii::app()->functions->sendVerificationCode($this->data['contact_phone'],$sms_code);
	    			
	    		}	     
	    		if ($theme_enabled_email_verification==2){
	    			$verification_type='email_verification';
	    			$email_code=Yii::app()->functions->generateCode(10);
	    			$params['email_verification_code']=$email_code;
	    			$params['status']='pending';
	    			FunctionsV3::sendEmailVerificationCode($this->data['email_address'],
	    			$email_code,$this->data);
	    		}	     
	    		
	    		if(!empty($verification_type)){
	    			$this->data['next_step']=$verification_type;
	    		}
	    		
	    		$DbExt=new DbExt; 
	    		if ( $DbExt->insertData("{{client}}",$params)){
	    			$client_id=Yii::app()->db->getLastInsertID();
	    			$this->msg=$this->t("Registration successful");
	    			$this->code=1;
	    			
	    			
	    			$avatar=AddonMobileApp::getAvatar( $client_id , array() );
	    			
	    			$this->details=array(
	    			   'token'=>$token,
	    			   'next_step'=>$this->data['next_step'],
	    			   'is_checkout'=>$is_checkout,
	    			   'client_id'=>$client_id,
	    			   'avatar'=>$avatar,
	    			   'client_name_cookie'=>$this->data['first_name']
	    			 ); 
	    			
	    			FunctionsK::sendCustomerWelcomeEmail($this->data);

	    			//update device client id
		   	   	   if (isset($this->data['device_id'])){
		   	   	       AddonMobileApp::updateDeviceInfo($this->data['device_id'],$client_id);
		   	   	   }    			
	    			
	    		} else $this->msg=$this->t("Something went wrong during processing your request. Please try again later.");
	        } else $this->msg=$this->t("Sorry but your email address already exist in our records.");	    				
		} else $this->msg=AddonMobileApp::parseValidatorError($Validator->getError());		
		$this->output();
	}
	
	public function actionGetPaymentOptions()
	{		
		if (!isset($this->data['merchant_id'])){
			$this->msg=$this->t("Merchant Id is is missing");
			$this->output();
		}

		$mtid=$this->data['merchant_id'];
		
		/*ADD CHECKING DISTANCE OF NEW ADDRESS */
		//dump($this->data);
		if(!isset($this->data['transaction_type'])){
			$this->data['transaction_type']='';
		}
		if ( $this->data['transaction_type']=="delivery"){
			$client_address=$this->data['street']." ";
			$client_address.=$this->data['city']." ";
			$client_address.=$this->data['state']." ";
			$client_address.=$this->data['zipcode']." ";
			
			$merchant_info='';
			if (!$merchantinfo=AddonMobileApp::getMerchantInfo($mtid)){
				$this->msg=$this->t("Merchant Id is is missing");
				$this->output();
				Yii::app()->end();
			} else {
				$merchant_address=$merchantinfo['street']." ";
				$merchant_address.=$merchantinfo['city']." ";
				$merchant_address.=$merchantinfo['state']." ";
				$merchant_address.=$merchantinfo['post_code']." ";
				$merchant_info=array(
				  'merchant_id'=>$merchantinfo['merchant_id'],
				  'address'=>$merchant_address,
				  'delivery_fee_raw'=>getOption($mtid,'merchant_delivery_charges')
				);
			}
			if($distance_new=AddonMobileApp::getDistanceNew($merchant_info,$client_address)){
			   if(isset($_GET['debug'])){
			      dump($distance_new);
			   }
			   $merchant_delivery_distance=''; 
			   if($distance_new['distance_type_raw']=="ft" || $distance_new['distance_type_raw']=="millimeter"){
	    	   	 // do nothing
	    	   } else {		   	  
	    	   	  if(is_numeric($merchant_delivery_distance)){
		    	   	  if ($merchant_delivery_distance<=$distance_new['distance']){
			    	  	 $this->msg=$this->t("Sorry but this merchant delivers only with in ").
			    	  	 $merchant_delivery_distance . " ". $distance_new['distance_type'];
			    	  	 $this->details=3;
					     $this->output();
					     Yii::app()->end();
			    	  }
	    	   	  }
	    	   }
			} else {
				 //$this->msg=$this->t("Failed calculating distance please try again");
	    	  	 //$this->details=3;
			     //$this->output();
			     //Yii::app()->end();
			}
		}
		/*ADD CHECKING DISTANCE OF NEW ADDRESS */
		
		/*SAVE TO ADDRESS*/
		if ( $this->data['transaction_type']=="delivery"){
		    if(!isset($this->data['save_address'])){
		    	$this->data['save_address']='';
		    }
		    if ($this->data['save_address']==2){
		    	if ( $client=AddonMobileApp::getClientTokenInfo($this->data['client_token'])){
		    		$params_address=array(
		    		  'client_id'=>$client['client_id'],
		    		  'street'=>isset($this->data['street'])?$this->data['street']:'',
		    		  'city'=>isset($this->data['city'])?$this->data['city']:'',
		    		  'state'=>isset($this->data['state'])?$this->data['state']:'',
		    		  'zipcode'=>isset($this->data['zipcode'])?$this->data['zipcode']:'',
		    		  'location_name'=>isset($this->data['location_name'])?$this->data['location_name']:'',
		    		  'country_code'=>Yii::app()->functions->getOptionAdmin('admin_country_set'),
		    		  'date_created'=>date('c'),
		    		  'ip_address'=>$_SERVER['REMOTE_ADDR']
		    		);
		    		$DbExt=new DbExt; 
		    		$DbExt->insertData("{{address_book}}",$params_address);
		    	}
		    }
		}
		/*SAVE TO ADDRESS*/
		
		$merchant_payment_list='';
		
		/*LIST OF PAYMENT AVAILABLE FOR MOBILE*/
		$mobile_payment=array('cod','paypal','pyr','pyp','atz','stp');
			
		$payment_list=getOptionA('paymentgateway');
		$payment_list=!empty($payment_list)?json_decode($payment_list,true):false;		
		
		$pay_on_delivery_flag=false;
		$paypal_flag=false;
		
		$paypal_credentials='';
		
		$stripe_publish_key='';
				
		/*check master switch for offline payment*/
		if(is_array($payment_list) && count($payment_list)>=1){
		   $payment_list=array_flip($payment_list);
		   
		    $merchant_switch_master_cod=getOption($mtid,'merchant_switch_master_cod');
			if($merchant_switch_master_cod==2){
			   unset($payment_list['cod']);
			}
			$merchant_switch_master_pyr=getOption($mtid,'merchant_switch_master_pyr');
			if($merchant_switch_master_pyr==2){
			   unset($payment_list['pyr']);
			}
		}
		
		if(is_array($payment_list) && count($payment_list)>=1){
		   $payment_list=array_flip($payment_list);
		}		

		if (AddonMobileApp::isArray($payment_list)){			
			foreach ($mobile_payment as $val) {				
				if(in_array($val,(array)$payment_list)){					
					switch ($val) {
						case "cod":			
						    if (Yii::app()->functions->isMerchantCommission($mtid)){
						    	$merchant_payment_list[]=array(
								  'icon'=>'fa-usd',
								  'value'=>$val,
								  'label'=>$this->t("Cash On delivery")
								);
						    	continue;
						    }
							if ( getOption($mtid,'merchant_disabled_cod')!="yes"){
								$merchant_payment_list[]=array(
								  'icon'=>'fa-usd',
								  'value'=>$val,
								  'label'=>$this->t("Cash On delivery")
								);
							}
							break;
					
						case "paypal":	
						case "pyp":	
												  
						  /*admin*/
						  if (Yii::app()->functions->isMerchantCommission($mtid)){						  	
						  	  if ( getOptionA('adm_paypal_mobile_enabled')=="yes"){
						  	  							  	  							  	  
						  	    $paypal_credentials=array(
							      'mode' => getOptionA('adm_paypal_mobile_mode'),
							      'card_fee'=>getOptionA('admin_paypal_fee')
							    );			  
							    if ( strtolower($paypal_credentials['mode'])=="sandbox"){
							  	   $paypal_credentials['client_id_sandbox']=getOptionA('adm_paypal_mobile_clientid');
							  	   $paypal_credentials['client_id_live']='';
							    } else {
							  	   $paypal_credentials['client_id_live']=getOptionA('adm_paypal_mobile_clientid');
							  	   $paypal_credentials['client_id_sandbox']='';
							    }						  
							  }
							  
							  if (!empty($paypal_credentials['client_id_live']) || 
							   !empty($paypal_credentials['client_id_sandbox']) ){
							     $paypal_flag=true;
							  }
							  
							  if ($paypal_flag){
							     $merchant_payment_list[]=array(
							       'icon'=>'fa-paypal',
							        'value'=>$val,
							        'label'=>$this->t("Paypal")
							     );
							  }
							  
						  	  continue;
						  }
						  
						  /*merchant*/
						  if (getOption($mtid,'mt_paypal_mobile_enabled') =="yes"){						      
							  							 							  
							  $paypal_credentials=array(
							    'mode' => strtolower(getOption($mtid,'mt_paypal_mobile_mode')),
							    'card_fee'=>getOption($mtid,'merchant_paypal_fee')							    
							    //'mode' => "nonetwork"
							  );
							  if ( strtolower($paypal_credentials['mode'])=="sandbox"){
							  	 $paypal_credentials['client_id_sandbox']=getOption($mtid,'mt_paypal_mobile_clientid');
							  	 $paypal_credentials['client_id_live']='';
							  } else {
							  	 $paypal_credentials['client_id_live']=getOption($mtid,'mt_paypal_mobile_clientid');
							  	 $paypal_credentials['client_id_sandbox']='';
							  }			
							  
							  if (!empty($paypal_credentials['client_id_live']) || 
							   !empty($paypal_credentials['client_id_sandbox']) ){
							     $paypal_flag=true;
							  }
							  
							  if ($paypal_flag){
							  	$merchant_payment_list[]=array(
							      'icon'=>'fa-paypal',
							      'value'=>$val,
							      'label'=>$this->t("Paypal")
							    );
							  }						  
							  				  
						   }
						   break;
						
						case "pyr":	
						    $pay_on_delivery_flag=true;
						   if (Yii::app()->functions->isMerchantCommission($mtid)){
						   	   $merchant_payment_list[]=array(
							    'icon'=>'fa-cc-visa',
							    'value'=>$val,
							    'label'=>$this->t("Pay On Delivery")
							   );
						   	   continue;
						   }
						   if ( getOption($mtid,'merchant_payondeliver_enabled')=="yes"){
						      $merchant_payment_list[]=array(
							    'icon'=>'fa-cc-visa',
							    'value'=>$val,
							    'label'=>$this->t("Pay On Delivery")
							  );
						   }
						   break;
						   
						case "atz":
							if (Yii::app()->functions->isMerchantCommission($mtid)){
								$merchant_payment_list[]=array(
								   'icon'=>'ion-card',
								   'value'=>$val,
								   'label'=>$this->t("Authorize.net")
								);
							} else {
								if(getOption($mtid,'merchant_enabled_autho')=="yes"){
									$merchant_payment_list[]=array(
									   'icon'=>'ion-card',
									   'value'=>$val,
									   'label'=>$this->t("Authorize.net")
									);
								}
							}
							break;
							
					   case "stp":
					   	
							if (Yii::app()->functions->isMerchantCommission($mtid)){
								
								$stripe_enabled=getOptionA('admin_stripe_enabled');
								if($stripe_enabled!="yes"){
									continue;
								}
								
								$mode=Yii::app()->functions->getOptionAdmin('admin_stripe_mode');  
			                    $mode=strtolower($mode);								
								if ( $mode=="sandbox"){
								   	$stripe_publish_key=getOptionA('admin_sandbox_stripe_pub_key');
								} else {
									$stripe_publish_key=getOptionA('admin_live_stripe_pub_key');
								}
								if(!empty($stripe_publish_key)){
									$merchant_payment_list[]=array(
									   'icon'=>'ion-card',
									   'value'=>$val,
									   'label'=>$this->t("Stripe")
									);
								}
							} else {
								if(getOption($mtid,'stripe_enabled')=="yes"){
									
									$stripe_enabled=getOption($mtid,'stripe_enabled');
									if($stripe_enabled!="yes"){
										continue;
									}
								
									$mode=Yii::app()->functions->getOption('stripe_mode',$mtid);   
				                    $mode=strtolower($mode);
				                    if ( $mode=="sandbox"){
									   $stripe_publish_key=getOption($mtid,'sandbox_stripe_pub_key');
				                    } else {
				                       $stripe_publish_key=getOption($mtid,'live_stripe_pub_key'); 
				                    }
									if(!empty($stripe_publish_key)){
										$merchant_payment_list[]=array(
										   'icon'=>'ion-card',
										   'value'=>$val,
										   'label'=>$this->t("Stripe")
										);
									}
								}
							}
							break;	
							   
						default:
							break;
					}					
				}			
			}
			
			$pay_on_delivery_list='';
			if ($pay_on_delivery_flag){
				if ($list=Yii::app()->functions->getPaymentProviderListActive()){
					foreach ($list as $val_payment) {						
						$pay_on_delivery_list[]=array(
						  'payment_name'=>$val_payment['payment_name'],
						  'payment_logo'=>AddonMobileApp::getImage($val_payment['payment_logo']),
						);
					}
				}				
			}
						
			if (AddonMobileApp::isArray($merchant_payment_list)){			
				
				/*pts*/
				$points_balance=0;
				if (AddonMobileApp::hasModuleAddon('pointsprogram')){
					if (getOptionA('points_enabled')==1){
						if ( $client=AddonMobileApp::getClientTokenInfo($this->data['client_token'])){
							$client_id=$client['client_id'];
						} else $client_id=0;
						$points_balance=PointsProgram::getTotalEarnPoints($client_id);
					}
				}
				
				$this->code=1;
				$this->msg="OK";
				$this->details=array(
				  'voucher_enabled'=>getOption($mtid,'merchant_enabled_voucher'),
				  'payment_list'=>$merchant_payment_list,
				  'pay_on_delivery_flag'=>$pay_on_delivery_flag,
				  'pay_on_delivery_list'=>$pay_on_delivery_list,
				  'paypal_flag'=>$paypal_flag==true?1:2,
				  'paypal_credentials'=>$paypal_credentials,
				  'stripe_publish_key'=>$stripe_publish_key,
				  'pts'=>array(
				    'balance'=>$points_balance,
				    'pts_label_input'=>getOptionA('pts_label_input')
				  )
				);
			} else $this->msg=$this->t("sorry but all payment options is not available");		
		} else $this->msg=$this->t("sorry but all payment options is not available");
				
		$this->output();	
	}
	
	public function actionPlaceOrder()
	{
		
		$DbExt=new DbExt; 
		
		if (isset($this->data['next_step'])){
			unset($this->data['next_step']);
		}	
				
		$Validator=new Validator;
		$req=array(
		  'merchant_id'=>$this->t("Merchant Id is is missing"),
		  'cart'=>$this->t("cart is empty"),
		  'transaction_type'=>$this->t("transaction type is missing"),
		  'payment_list'=>$this->t("payment method is missing"),
		  'client_token'=>$this->t("client token is missing")		  
		);
							
		$mtid=$this->data['merchant_id'];
		
		$default_order_status=getOption($mtid,'default_order_status');
	/*	dump('=>'.$default_order_status);
		dump($this->data);*/
				
		if ( !$client=AddonMobileApp::getClientTokenInfo($this->data['client_token'])){
			$Validator->msg[]=$this->t("sorry but your session has expired please login again");
		} 
		$client_id=$client['client_id'];
						
		//dump($this->data);
		//die();
		
		/*$this->msg='Your order has been placed. Reference # 123';
		$this->code=1;
	    $this->details=array(
	       'next_step'=>'receipt',
	       'order_id'=>123,
	       'payment_type'=>$this->data['payment_list']
	    );
        $this->output();*/
		
		//dump($this->data);
									    	
		$Validator->required($req,$this->data);
		if ($Validator->validate()){
			if ( $res=AddonMobileApp::computeCart($this->data)){								
				if (empty($res['validation_msg'])){
				   $json_data=AddonMobileApp::cartMobile2WebFormat($res,$this->data);
				   
				   if (AddonMobileApp::isArray($json_data)) {
					   $cart=$res['cart'];	
					   //dump($cart);
					   
					   if ($this->data['payment_list']=="cod" || 
					      $this->data['payment_list']=="pyr"  || 
					      $this->data['payment_list']=="ccr"  || 
					      $this->data['payment_list']=="ocr"  || 
					      $this->data['payment_list']=="obd" ){				      	
					      	if (!empty($default_order_status)){
		    					$status=$default_order_status;
		    				} else $status="pending";
					   } else $status=initialStatus();
					   
					   					   
					   $params=array(
					    'merchant_id'=>$this->data['merchant_id'],
					    'client_id'=>$client_id,
					    'json_details'=>json_encode($json_data),
					    'trans_type'=>$this->data['transaction_type'],
					    //'payment_type'=>Yii::app()->functions->paymentCode($this->data['payment_list']),
					    'payment_type'=>$this->data['payment_list'],
					    'sub_total'=>isset($cart['sub_total'])?$cart['sub_total']['amount']:0,
					    'tax'=>isset($cart['tax'])?$cart['tax']['tax']:0,
					    'taxable_total'=>isset($cart['tax'])?$cart['tax']['amount_raw']:0,
					    'total_w_tax'=>isset($cart['grand_total'])?$cart['grand_total']['amount']:0,
					    'status'=>$status,
					    'delivery_charge'=>isset($cart['delivery_charges'])?$cart['delivery_charges']['amount']:0,
					    'delivery_date'=>isset($this->data['delivery_date'])?$this->data['delivery_date']:'',
					    'delivery_time'=>isset($this->data['delivery_time'])?$this->data['delivery_time']:'',
					    'delivery_asap'=>isset($this->data['delivery_asap'])?$this->data['delivery_asap']:'',
					    'delivery_instruction'=>isset($this->data['delivery_instruction'])?$this->data['delivery_instruction']:'',					    
					    'packaging'=>isset($cart['packaging'])?$cart['packaging']['amount']:0,
					    'date_created'=>date('c'),
					    'ip_address'=>$_SERVER['REMOTE_ADDR'],
					    'order_change'=>isset($this->data['order_change'])?$this->data['order_change']:'',
					    'mobile_cart_details'=>isset($this->data['cart'])?$this->data['cart']:''
					   );
					   
					  /* dump($params);
					   die();*/
					   
					   /*add voucher if has one*/
					   if (isset($this->data['voucher_code'])){
		        	       if (!empty($this->data['voucher_amount'])){
		        	       	   $params['voucher_code']=$this->data['voucher_code'];
		        	       	   $params['voucher_amount']=$this->data['voucher_amount'];
		        	       	   $params['voucher_type']=$this->data['voucher_type'];
		        	       }
					   }  
					   
					   /*dump($params);
					   die();*/
					   
					   if (isset($this->data['payment_provider_name'])){
					   	   $params['payment_provider_name']=$this->data['payment_provider_name'];
					   }
					   
					   if (getOption($mtid,'merchant_tax_charges')==2){
		    		       $params['donot_apply_tax_delivery']=2;
		    		   }	    	
		    		   
		    		   if(isset($cart['discount'])){
		    		   	  $params['discounted_amount']=$cart['discount']['amount'];
		    		   	  $params['discount_percentage']=$cart['discount']['discount'];
		    		   }				
		    		   
					   /*Commission*/
					   if ( Yii::app()->functions->isMerchantCommission($mtid)){
							$admin_commision_ontop=Yii::app()->functions->getOptionAdmin('admin_commision_ontop');
							if ( $com=Yii::app()->functions->getMerchantCommission($mtid)){
								$params['percent_commision']=$com;			            		
								$params['total_commission']=($com/100)*$params['total_w_tax'];
								$params['merchant_earnings']=$params['total_w_tax']-$params['total_commission'];
								if ( $admin_commision_ontop==1){
									$params['total_commission']=($com/100)*$params['sub_total'];
									$params['commision_ontop']=$admin_commision_ontop;			            		
									$params['merchant_earnings']=$params['sub_total']-$params['total_commission'];
								}
							}			
							
							/** check if merchant commission is fixed  */
							$merchant_com_details=Yii::app()->functions->getMerchantCommissionDetails($mtid);
							
							if ( $merchant_com_details['commision_type']=="fixed"){
								$params['percent_commision']=$merchant_com_details['percent_commision'];
								$params['total_commission']=$merchant_com_details['percent_commision'];
								$params['merchant_earnings']=$params['total_w_tax']-$merchant_com_details['percent_commision'];
								
								if ( $admin_commision_ontop==1){			            		
								    $params['merchant_earnings']=$params['sub_total']-$merchant_com_details['percent_commision'];
								}
							}            
					    }/** end commission condition*/
					    					    
					    /*insert the order details*/				
					    $params['request_from']='mobile_app';  // tag the order to mobile app
					    					    
					    /*add paypal card fee */
					    if ($this->data['payment_list']=="paypal" || $this->data['payment_list']=="pyp"){
					    	if(isset($this->data['paypal_card_fee'])){
						    	if($this->data['paypal_card_fee']>0){
						    	   $params['card_fee']=$this->data['paypal_card_fee'];
						    	   $params['total_w_tax']=$params['total_w_tax']+$this->data['paypal_card_fee'];
						    	}
					    	}
					    	$params['payment_type']="pyp";
					    }				   
					    
					    /*pts*/
					    $pts=1;
						if (AddonMobileApp::hasModuleAddon('pointsprogram')){
							if (getOptionA('points_enabled')==1){
							    $pts=2;
							}
						}
						
						if($pts==2){
						    if(isset($this->data['pts_redeem_amount'])){
						       if($this->data['pts_redeem_amount']>0.001){
						       	  $params['points_discount']=unPrettyPrice($this->data['pts_redeem_amount']);
						       }					    
						    }			
						}
											  
					    if (!$DbExt->insertData("{{order}}",$params)){
					    	$this->msg=AddonMobileApp::t("ERROR: Cannot insert records.");
					    	$this->output();
					    }					    
					    
					    $order_id=Yii::app()->db->getLastInsertID();	
					    
					    /*pts*/
					    if(isset($this->data['earned_points'])){
						    if($pts==2){
								if(is_numeric($this->data['earned_points'])){
									PointsProgram::saveEarnPoints(
									  $this->data['earned_points'],
									  $params['client_id'],
									  $this->data['merchant_id'],
									  $order_id,
									  $params['payment_type']
									);
								}
							}
					    }
					    
					    if(isset($this->data['pts_redeem_amount'])){
					    	if($this->data['pts_redeem_amount']>0.001){
					    	   if($pts==2){
					    	      PointsProgram::saveExpensesPoints(
					    	        isset($this->data['pts_redeem_points'])?$this->data['pts_redeem_points']:0,
					    	        isset($this->data['pts_redeem_amount'])?$this->data['pts_redeem_amount']:0,
					    	        $params['client_id'],
					    	        $this->data['merchant_id'],
					    	        $order_id,
					    	        $params['payment_type']
					    	      );	
					    	   }
					    	}
					    }
					    					    
					    /*saved food item details*/	
					    foreach ($cart['cart'] as $val_item) {
					    	//dump($val_item);		
					    	$item_details=Yii::app()->functions->getFoodItem($val_item['item_id']);
					    	$discounted_price=$val_item['price'];
					    	if($item_details['discount']>0){
					    		$discounted_price=$discounted_price-$item_details['discount'];
					    	}
					    	
					    	$sub_item='';
					    	if (AddonMobileApp::isArray($val_item['sub_item'])){
					    		foreach ($val_item['sub_item'] as $key_sub => $val_sub) {					    			
					    			foreach ($val_sub as $val_subs) {
						    			$sub_item[]=array(
						    			   'addon_name'=>$val_subs['sub_item_name'],
						    			   'addon_category'=>$key_sub,
						    			   'addon_qty'=>$val_subs['qty']=="itemqty"?$val_item['qty']:$val_subs['qty'],
						    			   'addon_price'=>$val_subs['price']
						    			);
					    			}
					    		}
					    	}
					    						    						    						    					
                            $params_details=array(
					    	  'order_id'=>$order_id,
					    	  'client_id'=>$client_id,
					    	  'item_id'=>$val_item['item_id'],
					    	  'item_name'=>$val_item['item_name'],					    	  
					    	  'order_notes'=>isset($val_item['order_notes'])?$val_item['order_notes']:'',
					    	  'normal_price'=>$val_item['price'],
					    	  'discounted_price'=>$discounted_price,
					    	  'size'=>isset($val_item['size'])?$val_item['size']:'',
					    	  'qty'=>isset($val_item['qty'])?$val_item['qty']:'',
					    	  'cooking_ref'=>isset($val_item['cooking_ref'])?$val_item['cooking_ref']:'',
					    	  'addon'=>json_encode($sub_item),
					    	  'ingredients'=>isset($val_item['ingredients'])?json_encode($val_item['ingredients']):'',
					    	  'non_taxable'=>isset($val_item['non_taxable'])?$val_item['non_taxable']:1
					    	);
					    	//dump($params_details);							    	
					    	$DbExt->insertData("{{order_details}}",$params_details);			    	
					    }
					    //die();
					    					   					   
					    /*save the customer delivery address*/
					    if ( $this->data['transaction_type']=="delivery"){
						    $params_address=array(
						      'order_id'=>$order_id,
						      'client_id'=>$client_id,
						      'street'=>isset($this->data['street'])?$this->data['street']:'',
						      'city'=>isset($this->data['city'])?$this->data['city']:'',
						      'state'=>isset($this->data['state'])?$this->data['state']:'',
						      'zipcode'=>isset($this->data['zipcode'])?$this->data['zipcode']:'',
						      'location_name'=>isset($this->data['location_name'])?$this->data['location_name']:'',
						      'country'=>Yii::app()->functions->adminCountry(),
						      'date_created'=>date('c'),
						      'ip_address'=>$_SERVER['REMOTE_ADDR'],
						      'contact_phone'=>isset($this->data['contact_phone'])?$this->data['contact_phone']:''
						    );
						    //dump($params_address);
						    $DbExt->insertData("{{order_delivery_address}}",$params_address);
					    }
					    
					    $merchant_info=AddonMobileApp::getMerchantInfo($this->data['merchant_id']);					    
					    $merchant_name='';
					    if (AddonMobileApp::isArray($merchant_info)){
					    	$merchant_name=$merchant_info['restaurant_name'];
					    }
					    
					    $this->code=1;
					    $this->details=array(
					       'next_step'=>'receipt',
					       'order_id'=>$order_id,
					       'payment_type'=>$this->data['payment_list'],
					       'payment_details'=>array(
					         'total_w_tax'=>Yii::app()->functions->unPrettyPrice($params['total_w_tax']),
					         'currency_code'=>adminCurrencyCode(),
					         'paymet_desc'=>$this->t("Payment to merchant")." ".$merchant_name,
					         'total_w_tax_pretty'=>AddonMobileApp::prettyPrice($params['total_w_tax'])
					       )
					    );
					    				    
					    /*insert logs for food history*/
						$params_logs=array(
						  'order_id'=>$order_id,
						  'status'=> $status,
						  'date_created'=>date('c'),
						  'ip_address'=>$_SERVER['REMOTE_ADDR']
						);
						$DbExt->insertData("{{order_history}}",$params_logs);
					    					    
						$ok_send_notification=true;					   
					    switch ($this->data['payment_list'])
					    {					    	
					    	case "cod":
	    					case "ccr":	
	    					case "ocr":			    					
	    					case "pyr":
	    					    $this->msg=Yii::t("default","Your order has been placed.");
	    					    $this->msg.=" ".AddonMobileApp::t("Reference # ".$order_id);	    					    
	    						break;	    						
	    					case "obd":
	    					    /** Send email if payment type is Offline bank deposit*/
						    	$functionsk=new FunctionsK();
						    	$functionsk->MerchantSendBankInstruction($mtid,$params['total_w_tax'],$order_id);
	    					    $this->msg=Yii::t("default","Your order has been placed.");	    					    
	    					    $this->msg.=" ".AddonMobileApp::t("Reference # ".$order_id);
	    						break;	    					
	    					case "paypal":
	    					case "pyp":
	    						$this->details['next_step']='paypal_init';	    						
	    						$ok_send_notification=false;
	    						break;	
	    						
	    					case "atz":
	    						$this->details['next_step']='atz_init';	    						
	    						$ok_send_notification=false;
	    						break;	
	    							
	    					case "stp":
	    						$this->details['next_step']='stp_init';	    						
	    						$ok_send_notification=false;
	    						break;		
	    					default:	
	    					    $this->msg=Yii::t("default","Please wait while we redirect...");
	    					    break;
					    }
					    				   
					    /*send email to client and merchant*/
					    AddonMobileApp::sendOrderEmail($cart,$params,$order_id,$this->data,$ok_send_notification);
					      
					    /*send sms to merchant and client*/
					    AddonMobileApp::sendOrderSMS($cart,$params,$order_id,$this->data,$ok_send_notification);			    
					    
				   } else $this->msg=$this->t("something went wrong");
				} else $this->msg=$res['validation_msg'];
			} else $this->msg=$this->t("something went wrong");
		} else $this->msg=AddonMobileApp::parseValidatorError($Validator->getError());	
		
		$this->output();
	}
	
	public function actionGetMerchantInfo()
	{		
		if (!isset($this->data['merchant_id'])){
			$this->msg=$this->t("Merchant Id is is missing");
			$this->output();
		}	
		
		$mtid=$this->data['merchant_id'];
		
		if ( $data = AddonMobileApp::merchantInformation($this->data['merchant_id'])){							
			$opening_hours=AddonMobileApp::getOperationalHours($mtid);			
						
			$this->details=array(
			  'merchant_info'=>$data,
			  'opening_hours'=>$opening_hours
			);
			if ($payment_method=AddonMobileApp::getMerchantPaymentMethod($mtid)){
				$this->details['payment_method']=$payment_method;
			}				
			if ($review=AddonMobileApp::previewMerchantReview($mtid)){
				$review['date_created']=PrettyDateTime::parse(new DateTime($review['date_created']));
				$this->details['reviews']=Yii::app()->functions->translateDate($review);
			}
			
			$merchant_latitude=getOption($mtid,'merchant_latitude');
			$merchant_longtitude=getOption($mtid,'merchant_longtitude');
			if(!empty($merchant_latitude) && !empty($merchant_longtitude)){
				$this->details['maps']=array(
				  'merchant_latitude'=>$merchant_latitude,
				  'merchant_longtitude'=>$merchant_longtitude
				);
			}		
			
			$table_booking=2;
			if ( getOptionA('merchant_tbl_book_disabled')==2){
				$table_booking=1;
			} else {
				if ( getOption($mtid,'merchant_table_booking')=="yes"){
					$table_booking=1;
				}			
			}		
			$this->details['enabled_table_booking']=$table_booking;
			
			$this->code=1;
			$this->msg="OK";
		} else $this->msg=AddonMobileApp::t("sorry but merchant information is not available");
		
		$this->output();
	}
	
	public function actionBookTable()
	{
		$Validator=new Validator;
		
		$req=array(
		  'merchant_id'=>$this->t("merchant id is required"),
		  'number_guest'=>$this->t("number of guest is srequired"),
		  'date_booking'=>$this->t("date of booking is required"),
		  'booking_time'=>$this->t("time is required"),
		  'booking_name'=>$this->t("name is required"),		  
		);
		$Validator->required($req,$this->data);
		
		$time_1=date('Y-m-d g:i:s a');
   	  	$time_2=$this->data['date_booking']." ".$this->data['booking_time'];
   	  	$time_2=date("Y-m-d g:i:s a",strtotime($time_2));	       	  	        	  	 
   	  	$time_diff=Yii::app()->functions->dateDifference($time_2,$time_1);	       	  	    	  	
   	  	if (AddonMobileApp::isArray($time_diff)){
   	  		if ($time_diff['hours']>0){   	  			
   	  			$Validator->msg[]=AddonMobileApp::t("you have selected a date/time that already past");
   	  		}   	  	
   	  		if ($time_diff['days']>0){   	   	  			
   	  			$Validator->msg[]=AddonMobileApp::t("you have selected a date/time that already past");
   	  		}   	  	
   	  	}	   	  	
   	  	
		if ($Validator->validate()){
			
			$merchant_id=$this->data['merchant_id'];
			
			$full_booking_time=$this->data['date_booking']." ".$this->data['booking_time'];
			
			$full_booking_day=strtolower(date("D",strtotime($full_booking_time)));			
			$booking_time=date('h:i A',strtotime($full_booking_time));			
								
			
			if ( !Yii::app()->functions->isMerchantOpenTimes($merchant_id,$full_booking_day,$booking_time)){
				$this->msg=t("Sorry but we are closed on"." ".date("F,d Y h:ia",strtotime($full_booking_time))).
				"\n".t("Please check merchant opening hours");
			    $this->output();
			}					
					
			$now=isset($this->data['date_booking'])?$this->data['date_booking']:'';			
			$merchant_close_msg_holiday='';
		    $is_holiday=false;
		    if ( $m_holiday=Yii::app()->functions->getMerchantHoliday($merchant_id)){
	      	    if (in_array($now,(array)$m_holiday)){
	      	   	    $is_holiday=true;
	      	    }
		    }
		    if ( $is_holiday==true){
		    	$merchant_close_msg_holiday=!empty($merchant_close_msg_holiday)?$merchant_close_msg_holiday:t("Sorry but we are on holiday on")." ".date("F d Y",strtotime($now));
		    	$this->msg=$merchant_close_msg_holiday;
		    	$this->output();
		    }		    
		    		    
		    $fully_booked_msg=Yii::app()->functions->getOption("fully_booked_msg",$merchant_id);
		    if (!Yii::app()->functions->bookedAvailable($merchant_id)){
		    	if (!empty($fully_booked_msg)){
		    		$this->msg=t($fully_booked_msg);
		    	} else $this->msg=t("Sorry we are fully booked for that day");			 	
			 	$this->output();
			}
						
			$db_ext=new DbExt;					
			$params=array(
			  'merchant_id'=>isset($this->data['merchant_id'])?$this->data['merchant_id']:'',
			  'number_guest'=>isset($this->data['number_guest'])?$this->data['number_guest']:'',
			  'date_booking'=>isset($this->data['date_booking'])?$this->data['date_booking']:'',
			  'booking_time'=>isset($this->data['booking_time'])?$this->data['booking_time']:'',
			  'booking_name'=>isset($this->data['booking_name'])?$this->data['booking_name']:'',
			  'email'=>isset($this->data['email'])?$this->data['email']:'',
			  'mobile'=>isset($this->data['mobile'])?$this->data['mobile']:'',
			  'booking_notes'=>isset($this->data['booking_notes'])?$this->data['booking_notes']:'',
			  'date_created'=>date('c'),
			  'ip_address'=>$_SERVER['REMOTE_ADDR']
			);				
					
			$merchant_booking_receiver=Yii::app()->functions->getOption("merchant_booking_receiver",$merchant_id);
			$merchant_booking_tpl=Yii::app()->functions->getOption("merchant_booking_tpl",$merchant_id);
			
			if (empty($merchant_booking_tpl)){
			    $merchant_booking_tpl=EmailTPL::bookingTPL();
			}
			$merchant_booking_receive_subject=Yii::app()->functions->getOption("merchant_booking_receive_subject",
			$merchant_id);
			
			$sender='no-reply@'.$_SERVER['HTTP_HOST'];
			
			
			if ( !$merchant_info=Yii::app()->functions->getMerchant($merchant_id)){			
				$merchant_info['restaurant_name']=$this->t("None");
			}
			
			$h='';
			$h.='<table border="0">';
			$h.='<tr>';
			$h.='<td>'.Yii::t("default","Restaurant name").'</td>';
			$h.='<td>: '.ucwords($merchant_info['restaurant_name']).'</td>';
			$h.='</tr>';
			
			$h.='<tr>';
			$h.='<td>'.Yii::t("default","Number Of Guests").'</td>';
			$h.='<td>: '.$params['number_guest'].'</td>';
			$h.='</tr>';
			
			$h.='<tr>';
			$h.='<td>'.Yii::t("default","Date Of Booking").'</td>';
			$h.='<td>: '.$params['date_booking'].'</td>';
			$h.='</tr>';
			
			$h.='<tr>';
			$h.='<td>'.Yii::t("default","Time").'</td>';
			$h.='<td>: '.$params['booking_time'].'</td>';
			$h.='</tr>';
			
			$h.='<tr>';
			$h.='<td>'.Yii::t("default","Name").'</td>';
			$h.='<td>: '.$params['booking_name'].'</td>';
			$h.='</tr>';
			
			$h.='<tr>';
			$h.='<td>'.Yii::t("default","Email").'</td>';
			$h.='<td>: '.$params['email'].'</td>';
			$h.='</tr>';
			
			$h.='<tr>';
			$h.='<td>'.Yii::t("default","Mobile").'</td>';
			$h.='<td>: '.$params['mobile'].'</td>';
			$h.='</tr>';
			
			$h.='<tr>';
			$h.='<td>'.Yii::t("default","Message").'</td>';
			$h.='<td>: '.$params['booking_notes'].'</td>';			
			$h.='</tr>';
			
			$h.='</table>';						
			
			$template=Yii::app()->functions->smarty("booking-information",$h,$merchant_booking_tpl);
									
			if ( $db_ext->insertData('{{bookingtable}}',$params)){
				$this->details=Yii::app()->db->getLastInsertID();
			    $this->code=1;
			    $this->msg=Yii::t("default","we have receive your booking").".<br/>";
			    $this->msg.=$this->t("your booking reference number is")." #".$this->details;
			    			    
			    if (!empty($merchant_booking_receiver) && !empty($template)){
			       sendEmail($merchant_booking_receiver,$sender,$merchant_booking_receive_subject,$template);
			    }			    
			} else $this->msg=Yii::t("default","Something went wrong during processing your request. Please try again later.");
			
		} else $this->msg=AddonMobileApp::parseValidatorError($Validator->getError());		
		$this->output();
	}
	
	public function actionMerchantReviews()
	{
	
		if (isset($this->data['merchant_id'])){
			if ( $res=Yii::app()->functions->getReviewsList($this->data['merchant_id'])){
				$data='';
				foreach ($res as $val) {
					$prety_date=PrettyDateTime::parse(new DateTime($val['date_created']));
					$data[]=array(
					  'client_name'=>empty($val['client_name'])?$this->t("not available"):$val['client_name'],
					  'review'=>$val['review'],
					  'rating'=>$val['rating'],
					  'date_created'=>Yii::app()->functions->translateDate($prety_date)
					);
				}
				$this->code=1;$this->msg="OK";
				$this->details=$data;
			} else $this->msg=$this->t("no current reviews");
		} else $this->msg=$this->t("Merchant id is missing");
		$this->output();	
	}
	
	public function actionAddReview()
	{		
		$Validator=new Validator;
		$req=array(
		  'rating'=>$this->t("rating is required"),
		  'review'=>$this->t("review is required"),
		  'merchant_id'=>$this->t("Merchant id is missing")
		);
		$Validator->required($req,$this->data);

		if ( !$client=AddonMobileApp::getClientTokenInfo($this->data['client_token'])){
			$Validator->msg[]=$this->t("Sorry but you need to login to write a review.");
		} 
		$client_id=$client['client_id'];
		$mtid=$this->data['merchant_id'];
		
		if ( $Validator->validate()){
						
			$params=array(
	    	  'merchant_id'=>$mtid,
	    	  'client_id'=>$client_id,
	    	  'review'=>$this->data['review'],
	    	  'date_created'=>date('c'),
	    	  'rating'=>$this->data['rating']
	    	);		 
		    	
			/** check if user has bought from the merchant*/		    	
	    	if ( Yii::app()->functions->getOptionAdmin('website_reviews_actual_purchase')=="yes"){
	    		$functionk=new FunctionsK();
	    	    if (!$functionk->checkIfUserCanRateMerchant($client_id,$mtid)){
	    	    	$this->msg=$this->t("Reviews are only accepted from actual purchases!");
	    	    	$this->output();
	    	    }
	    	    		    	    	    	   
	    	    if (!$functionk->canReviewBasedOnOrder($client_id,$mtid)){
	    		   $this->msg=$this->t("Sorry but you can make one review per order");
	    	       $this->output();
	    	    }	  		   
	    	    
	    	    if ( $ref_orderid=$functionk->reviewByLastOrderRef($client_id,$this->data['merchant-id'])){
	    	    	$params['order_id']=$ref_orderid;
	    	    }
	    	}
	    	$DbExt=new DbExt;    	
	    	if ( $DbExt->insertData("{{review}}",$params)){
	    		$this->code=1;
	    		$this->msg=Yii::t("default","Your review has been published.");	    		    	
	    	} else $this->msg=Yii::t("default","ERROR: cannot insert records.");		
		} else $this->msg=AddonMobileApp::parseValidatorError($Validator->getError());	
		$this->output();	
	}
	
	public function actionBrowseRestaurant()
	{
		$DbExt=new DbExt;  
		$DbExt->qry("SET SQL_BIG_SELECTS=1");		
		
		$start=0;
		$limit=200;
		
		$and='';
		if (isset($this->data['restaurant_name'])){
			$and=" AND restaurant_name LIKE '".$this->data['restaurant_name']."%'";
		}	
		
		$stmt="SELECT SQL_CALC_FOUND_ROWS a.*,
    	(
    	select option_value
    	from 
    	{{option}}
    	WHERE
    	merchant_id=a.merchant_id
    	and
    	option_name='merchant_photo'
    	) as merchant_logo
    	        
    	 FROM
    	{{view_merchant}} a    	
    	WHERE is_ready ='2'
    	AND status in ('active')
    	$and
    	ORDER BY membership_expired,is_featured DESC
    	LIMIT $start,$limit    	
    	";    			
		
		if (isset($_GET['debug'])){
			dump($stmt);
		}

		if ($res=$DbExt->rst($stmt)){
			$data='';
			
			$total_records=0;
			$stmtc="SELECT FOUND_ROWS() as total_records";
	 		if ($resp=$DbExt->rst($stmtc)){			 			
	 			$total_records=$resp[0]['total_records'];
	 		}			 		
			 		
			foreach ($res as $val) {
								
				/*check if mechant is open*/
	 			$open=AddonMobileApp::isMerchantOpen($val['merchant_id']);
	 			
		        /*check if merchant is commission*/
		        $cod=AddonMobileApp::isCashAvailable($val['merchant_id']);
		        $online_payment='';
		        
		        $tag='';
		        $tag_raw='';
		        if ($open==true){
		        	$tag=$this->t("open");
		        	$tag_raw='open';
		        	if ( getOption( $val['merchant_id'] ,'merchant_close_store')=="yes"){
		        		$tag=$this->t("close");
		        		$tag_raw='close';
		        	}		        	
		        	if (getOption( $val['merchant_id'] ,'merchant_preorder')==1){
		        		$tag=$this->t("pre-order");
		        		$tag_raw='pre-order';
		        	}
		        } else  {
		        	$tag=$this->t("close");
		        	$tag_raw='close';
		        	if (getOption( $val['merchant_id'] ,'merchant_preorder')==1){
		        		$tag=$this->t("pre-order");
		        		$tag_raw='pre-order';
		        	}
		        }			 		
		        
		        $minimum_order=getOption($val['merchant_id'],'merchant_minimum_order');
	 			if(!empty($minimum_order)){
		 			$minimum_order=displayPrice(getCurrencyCode(),prettyFormat($minimum_order));		 			
	 			}
	 			
	 			$delivery_fee=getOption($val['merchant_id'],'merchant_delivery_charges');
	 			if (!empty($delivery_fee)){
	 				$delivery_fee=displayPrice(getCurrencyCode(),prettyFormat($delivery_fee));
	 			}
				        
				$data[]=array(
	 			  'merchant_id'=>$val['merchant_id'],
	 			  'restaurant_name'=>$val['restaurant_name'],
	 			  'address'=>$val['street']." ".$val['city']." ".$val['state']." ".$val['post_code'],
	 			  'ratings'=>Yii::app()->functions->getRatings($val['merchant_id']),
	 			  'cuisine'=>AddonMobileApp::prettyCuisineList($val['cuisine']),	 			  
	 			  'delivery_fee'=>$delivery_fee,			 			  
			 	  'minimum_order'=>$minimum_order,
	 			  'delivery_est'=>getOption($val['merchant_id'],'merchant_delivery_estimation'),
	 			  'is_open'=>$tag,
	 			  'tag_raw'=>$tag_raw,
	 			  'payment_options'=>array(
	 			    'cod'=>$cod,
	 			    'online'=>$online_payment
	 			  ),			 			 
	 			  'logo'=>AddonMobileApp::getMerchantLogo($val['merchant_id']),	 			  
	 			  'map_coordinates'=>array(
	 			    'latitude'=>!empty($val['latitude'])?$val['latitude']:'',
	 			    'lontitude'=>!empty($val['lontitude'])?$val['lontitude']:'',
	 			  ),
	 			  'offers'=>AddonMobileApp::getMerchantOffers($val['merchant_id'])
	 			);
			}
			$this->details=array(
	 		  'total'=>$total_records,
	 		  'data'=>$data
	 		);
	 		$this->code=1;$this->msg="Ok";
	 		$this->output();
		} else $this->msg=$this->t("No restaurant found");
		$this->output();
	}
	
	public function actiongetProfile()
	{	
		if ( $res=AddonMobileApp::getClientTokenInfo($this->data['client_token'])){			
			$this->code=1;
			$this->msg="OK";
			$avatar=AddonMobileApp::getAvatar( $res['client_id'] , $res );
			$res['avatar']=$avatar;
			$this->details=$res;
		} else $this->msg=$this->t("not login");
		$this->output();
	}
	
	public function actionsaveProfile()
	{		
		if ( $res=AddonMobileApp::getClientTokenInfo($this->data['client_token'])){
						
			/*check if mobile number is already exists*/
			if (isset($this->data['contact_phone'])){
			$functionsk=new FunctionsK();
				if ($functionsk->CheckCustomerMobile($this->data['contact_phone'],$res['client_id'])){
					$this->msg= $this->t("Sorry but your mobile number is already exist in our records");
					$this->output();
					Yii::app()->end();
				}		
			}			
			
			$params=array(
			  'first_name'=>$this->data['first_name'],
			  'last_name'=>$this->data['last_name'],
			  'contact_phone'=>isset($this->data['contact_phone'])?$this->data['contact_phone']:'',
			  'date_modified'=>date('c'),
			  'ip_address'=>$_SERVER['REMOTE_ADDR']
			);
			if (!empty($this->data['password'])){
				$params['password']=md5($this->data['password']);
			}					
			$DbExt=new DbExt;  
			if($DbExt->updateData("{{client}}",$params,'client_id',$res['client_id'])){
				$this->code=1;
				$this->msg=$this->t("your profile has been successfully updated");				
			} else $this->msg=$this->t("something went wrong during processing your request");
		} else $this->msg=$this->t("it seems that your token has expired. please re login again");
		$this->output();
	}
	
	public function actionLogin()
	{
		
		/*check if email address is blocked by admin*/	    	
    	if ( FunctionsK::emailBlockedCheck($this->data['email_address'])){
    		$this->msg=t("Sorry but your email address is blocked by website admin");
    		$this->output();
    	}	    	
    	    
    	$Validator=new Validator;
		$req=array(
		  'email_address'=>$this->t("email address is required"),
		  'password'=>$this->t("password is required")		  
		);
		$Validator->required($req,$this->data);
    	
		if ( $Validator->validate()){
		   $stmt="SELECT * FROM
		   {{client}}
		    WHERE
	    	email_address=".Yii::app()->db->quoteValue($this->data['email_address'])."
	    	AND
	    	password=".Yii::app()->db->quoteValue(md5($this->data['password']))."
	    	AND
	    	status IN ('active')
	    	LIMIT 0,1
		   ";		   
		   $DbExt=new DbExt; 
		   if ($res=$DbExt->rst($stmt)){
		   	   $res=$res[0];
		   	   $client_id=$res['client_id'];
		   	   $token=AddonMobileApp::generateUniqueToken(15,$this->data['email_address']);
		   	   $params=array(
		   	     'token'=>$token,
		   	     'last_login'=>date('c'),
		   	     'ip_address'=>$_SERVER['REMOTE_ADDR']		   	     
		   	   );		   	   
		   	   if ($DbExt->updateData("{{client}}",$params,'client_id',$client_id)){
		   	   	   $this->code=1;
		   	   	   $this->msg=$this->t("Login Okay");
		   	   	   
		   	   	   $avatar=''; $client_name='';
		   	   	   $avatar=AddonMobileApp::getAvatar( $client_id , $res );		   	   	   
		   	   	   
		   	   	   $this->details=array(
		   	   	     'token'=>$token,
		   	   	     'next_steps'=>isset($this->data['next_steps'])?$this->data['next_steps']:'',
		   	   	     'has_addressbook'=>AddonMobileApp::hasAddressBook($client_id)?2:1,
		   	   	     'avatar'=>$avatar,
		   	   	     'client_name_cookie'=>$res['first_name']
		   	   	   );
		   	   	   
		   	   	   //update device client id
		   	   	   if (isset($this->data['device_id'])){
		   	   	       AddonMobileApp::updateDeviceInfo($this->data['device_id'],$client_id);
		   	   	   }
		   	   	   
		   	   } else $this->msg=$this->t("something went wrong during processing your request");
		   } else $this->msg=$this->t("Login Failed. Either username or password is incorrect");
		} else $this->msg=AddonMobileApp::parseValidatorError($Validator->getError());	    	
    	$this->output();
	}
	
	public function actionForgotPassword()
	{		
		$Validator=new Validator;
		$req=array(
		  'email_address'=>$this->t("email address is required")		  
		);
		$Validator->required($req,$this->data);
				
		if ( $Validator->validate()){
		   if ( $res=yii::app()->functions->isClientExist($this->data['email_address']) ){					
			$token=md5(date('c'));
			$params=array('lost_password_token'=>$token);					
			$DbExt=new DbExt;
			if ($DbExt->updateData("{{client}}",$params,'client_id',$res['client_id'])){
				$this->code=1;						
				$this->msg=Yii::t("default","We sent your forgot password link, Please follow that link. Thank You.");
				//send email					
				$tpl=EmailTPL::forgotPass($res,$token);			    
			    $sender='';
                $to=$res['email_address'];		                
                if (!sendEmail($to,$sender,Yii::t("default","Forgot Password"),$tpl)){		    			                	
                	$this->details="failed";
                } else $this->details="mail ok";
                				
			} else $this->msg=Yii::t("default","ERROR: Cannot update records");				
		} else $this->msg=Yii::t("default","Sorry but your Email address does not exist in our records.");
		} else $this->msg=AddonMobileApp::parseValidatorError($Validator->getError());	
		$this->output();
	}
	
	public function actiongetOrderHistory()
	{		
		if ( $res=AddonMobileApp::getClientTokenInfo($this->data['client_token'])) {			
			if ( $order=Yii::app()->functions->clientHistyOrder($res['client_id'])){
				$this->code=1;
				$this->msg="Ok";
				$data='';
				foreach ($order as $val) {
					$data[]=array(
					  'order_id'=>$val['order_id'],
					  'title'=>"#".$val['order_id']." ".$val['merchant_name']." ".Yii::app()->functions->translateDate(prettyDate($val['date_created'])),
					  'status'=>AddonMobileApp::t($val['status'])
					);
				}
				$this->details=$data;
			} else $this->msg =$this->t("you don't have any orders yet");
		} else {
			$this->msg=$this->t("sorry but your session has expired please login again");
			$this->code=3;
		}
		$this->output();
	}
	
	public function actionOrdersDetails()
	{		
		$trans=getOptionA('enabled_multiple_translation'); 		
		if ( $res=AddonMobileApp::getClientTokenInfo($this->data['client_token'])) {			 
			 if ( $res=AddonMobileApp::getOrderDetails($this->data['order_id'])){			 	  
			 	  
			 	  $data='';
			 	  foreach ($res as $val) {
			 	  	 
			 	  	 if ( $trans==2 && isset($_GET['lang_id'])){
			 	  	 	 $lang_id=$_GET['lang_id'];
			 	  	 	 $val['item_name']=AddonMobileApp::translateItem('item',$val['item_name'],
			 	  	 	 $val['item_id'],'item_name_trans');
			 	  	 }
			 	  	
			 	  	 $data[]=array(
			 	  	   'item_name'=>$val['qty']."x ".$val['item_name']			 	  	   
			 	  	 );
			 	  }			 	  
			 	  $history_data='';
			 	  if ($history=FunctionsK::orderHistory($this->data['order_id'])){
			 	  	 foreach ($history as $val) {
			 	  	 	$history_data[]=array(
			 	  	 	  'date_created'=>FormatDateTime($val['date_created'],true),
			 	  	 	  'status'=>AddonMobileApp::t($val['status']),
			 	  	 	  'remarks'=>$val['remarks']
			 	  	 	);
			 	  	 }
			 	  }			 
			 	  
			 	  $stmt="SELECT 
			 	  request_from,
			 	  payment_type,
			 	  trans_type
			 	   FROM
			 	  {{order}}
			 	  WHERE 
			 	  order_id=".AddonMobileApp::q($this->data['order_id'])."
			 	  LIMIT 0,1
			 	  ";
			 	  $DbExt=new DbExt;
			 	  $order_from='web';
			 	  if ($resp=$DbExt->rst($stmt)){
			 	  	 $order_from=$resp[0];
			 	  } else {
			 	  	 $order_from=array(
			 	  	   'request_from'=>'web'
			 	  	 );
			 	  }			 
			 	  
			 	  $this->details=array(
			 	    'order_id'=>$this->data['order_id'],
			 	    'order_from'=>$order_from,
			 	    'total'=>AddonMobileApp::prettyPrice($res[0]['total_w_tax']),
			 	    'item'=>$data,
			 	    'history_data'=>$history_data
			 	  );
			 	  $this->code=1; $this->msg="OK";
			 } else $this->msg=$this->t("no item found");		
		} else $this->msg=$this->t("sorry but your session has expired please login again");
		$this->output();
	}
	
	public function actiongetAddressBookDialog()
	{
		$this->actiongetAddressBook();
	}

	public function actiongetAddressBook()
	{
		if ( $res=AddonMobileApp::getClientTokenInfo($this->data['client_token'])) {			 
			if ( $resp= AddonMobileApp::getAddressBook($res['client_id'])){
				$this->code=1;
				$this->msg="OK";
				$this->details=$resp;
			} else $this->msg = $this->t("no results");
		} else {
			$this->msg=$this->t("sorry but your session has expired please login again");
			$this->code=3;
		}	
		$this->output();
	}
	
	public function actionGetAddressBookDetails()
	{		
		if ( $res=AddonMobileApp::getClientTokenInfo($this->data['client_token'])) {	
			 if ( $resp= Yii::app()->functions->getAddressBookByID($this->data['id'])){			 	 
			 	 $this->code=1; $this->msg="OK";
			 	 $this->details=$resp;
			 } else $this->msg=$this->t("address book details not available");
		} else $this->msg=$this->t("sorry but your session has expired please login again");
		$this->output();
	}
	
	public function actionSaveAddressBook()
	{	
		$DbExt=new DbExt;
		if ( $res=AddonMobileApp::getClientTokenInfo($this->data['client_token'])) {	
			
			if (isset($this->data['as_default'])){
			   if ($this->data['as_default']==2){
			   	   $stmt="UPDATE 
			   	   {{address_book}}
			   	   SET as_default ='1'
			   	   ";
			   	   $DbExt->qry($stmt);
			   }			
			}					
			$params=array(
			  'client_id'=>$res['client_id'],
			  'street'=>isset($this->data['street'])?$this->data['street']:'',
			  'city'=>isset($this->data['city'])?$this->data['city']:'',
			  'state'=>isset($this->data['state'])?$this->data['state']:'',
			  'zipcode'=>isset($this->data['zipcode'])?$this->data['zipcode']:'',
			  'location_name'=>isset($this->data['location_name'])?$this->data['location_name']:'',
			  'as_default'=>isset($this->data['as_default'])?$this->data['as_default']:1,
			  'date_created'=>date('c'),
			  'ip_address'=>$_SERVER['REMOTE_ADDR'],
			  'country_code'=>Yii::app()->functions->adminSetCounryCode()
			);							
			if ( $this->data['action']=="add"){
				if ( $DbExt->insertData("{{address_book}}",$params)){
					$this->code=1;
					$this->msg="address book added";
					$this->details=$this->data['action'];
				} else $this->msg=$this->t("something went wrong during processing your request");	
			} else {
				unset($params['client_id']);
				unset($params['date_created']);
				if ( $DbExt->updateData("{{address_book}}",$params,'id',$this->data['id'])){
					$this->code=1;				
					$this->msg="successfully updated";
					$this->details=$this->data['action'];
				} else $this->msg=$this->t("something went wrong during processing your request");		
			}
		} else $this->msg=$this->t("sorry but your session has expired please login again");
		$this->output();
	}
	
	public function actionDeleteAddressBook()
	{
		$DbExt=new DbExt;
		if ( $res=AddonMobileApp::getClientTokenInfo($this->data['client_token'])) {				
			if ( $resp=Yii::app()->functions->getAddressBookByID($this->data['id'])){
				if ( $res['client_id']==$resp['client_id']){
					$stmt="
					DELETE FROM {{address_book}}
					WHERE
					id=".self::q($this->data['id'])."
					";
					if ( $DbExt->qry($stmt)){
						$this->code=1;
						$this->msg="OK";
					} else $this->msg=$this->t("something went wrong during processing your request");		
				} else $this->msg=$this->t("sorry but you cannot delete this records");
			} else $this->msg=$this->t("address book id not found");
		} else $this->msg=$this->t("sorry but your session has expired please login again");
		$this->output();	
	}
	
	public function actionreOrder()
	{	
		if ( $res=AddonMobileApp::getClientTokenInfo($this->data['client_token'])) {	
			 if ( $resp=Yii::app()->functions->getOrderInfo($this->data['order_id'])){
			 	  $cart=!empty($resp['mobile_cart_details'])?json_decode($resp['mobile_cart_details'],true):false;
			 	  //dump($cart);
			 	  if ( $cart!=false){
			 	  	  $this->msg="OK";
			 	  	  $this->details=array(
			 	  	    'merchant_id'=>$resp['merchant_id'],
			 	  	    'cart'=>$cart,			 	  	    
			 	  	  );
			 	  	  $this->code=1;
			 	  } else $this->msg=$this->t("something went wrong during processing your request");			 
			 } else $this->msg=$this->t("sorry but we cannot find the order details");
		} else $this->msg=$this->t("sorry but your session has expired please login again");
		$this->output();	
	}
	
	public function actionregisterUsingFb()
	{
		$DbExt=new DbExt;
		
		if(!isset($this->data['email'])){
			$this->msg=$this->t("Email address is missing");
			$this->output();
		}	
				
		if (!empty($this->data['email']) && !empty($this->data['first_name'])){			
			if ( FunctionsK::emailBlockedCheck($this->data['email'])){
	    		$this->msg=$this->t("Sorry but your facebook account is blocked by website admin");
	    		$this->output();
	    	}	   
	    		   	    	 
	    	$token=AddonMobileApp::generateUniqueToken(15,$this->data['email']);
	    	
	    	//$name=explode(" ",$this->data['name']);	    	
	    	
	    	$params=array(
	    	  'social_strategy'=>'fb_mobile',
	    	  'email_address'=>$this->data['email'],
	    	  'first_name'=>isset($this->data['first_name'])?$this->data['first_name']:'' ,
	    	  'last_name'=>isset($this->data['last_name'])?$this->data['last_name']:'' ,
	    	  'token'=>$token,
	    	  'last_login'=>date('c')
	    	);
	    		    		    	
	    	if ( $res=AddonMobileApp::checkifEmailExists($this->data['email'])){
	    		// update
	    		unset($params['email_address']);
	    		$client_id=$res['client_id'];
	    		if (empty($res['password'])){
	    			$params['password']=md5($this->data['fbid']);
	    		}		    		
	    		if ($DbExt->updateData("{{client}}",$params,'client_id',$client_id)){
	    		   $this->code=1;
		   	   	    $this->msg=$this->t("Login Okay");
		   	   	    
		   	   	    $avatar=AddonMobileApp::getAvatar( $client_id , $res );
		   	   	    
		   	   	    $this->details=array(
		   	   	      'token'=>$token,
		   	   	      'next_steps'=>isset($this->data['next_steps'])?$this->data['next_steps']:'',
		   	   	      'has_addressbook'=>AddonMobileApp::hasAddressBook($client_id)?2:1,
		   	   	      'avatar'=>$avatar,
		   	   	      'client_name_cookie'=>$res['first_name']
		   	   	    );
		   	   	    
		   	   	    //update device client id
		   	   	   if (isset($this->data['device_id'])){
		   	   	       AddonMobileApp::updateDeviceInfo($this->data['device_id'],$client_id);
		   	   	   }
		   	   	    
	    		} else $this->msg=$this->t("something went wrong during processing your request");
	    	} else {
	    		// insert
	    		$params['date_created']=date('c');
	    		$params['password']=md5($this->data['fbid']);
	    		$params['ip_address']=$_SERVER['REMOTE_ADDR'];
	    		
	    		if ($DbExt->insertData("{{client}}",$params)){
	    			$client_id=Yii::app()->db->getLastInsertID();
	    			$this->code=1;
		   	   	    $this->msg=$this->t("Login Okay");
		   	   	    
		   	   	    $avatar=AddonMobileApp::getAvatar( $client_id , array() );
		   	   	    
		   	   	    $this->details=array(
		   	   	      'token'=>$token,
		   	   	      'next_steps'=>isset($this->data['next_steps'])?$this->data['next_steps']:'',
		   	   	      'has_addressbook'=>AddonMobileApp::hasAddressBook($client_id)?2:1,
		   	   	      'avatar'=>$avatar,
		   	   	      'client_name_cookie'=>$this->data['first_name']
		   	   	    );
		   	   	    
		   	   	   //update device client id
		   	   	   if (isset($this->data['device_id'])){
		   	   	       AddonMobileApp::updateDeviceInfo($this->data['device_id'],$client_id);
		   	   	   }
		   	   	    
	    		} else $this->msg=$this->t("something went wrong during processing your request");
	    	}		    	
		} else $this->msg=$this->t("failed. missing email and name");
		$this->output();	
	}
	
	public function actionregisterMobile()
	{		
		$DbExt=new DbExt;
		$params['device_id']=isset($this->data['registrationId'])?$this->data['registrationId']:'';
		$params['device_platform']=isset($this->data['device_platform'])?$this->data['device_platform']:'';
		
		if (isset($this->data['client_token'])){
			if ( $client=AddonMobileApp::getClientTokenInfo($this->data['client_token'])) {					
				$params['client_id']=$client['client_id'];
			} else {
				/*$this->msg="Client id is missing";
				$this->output();*/
			}		
		}
					
		if (!empty($this->data['registrationId'])){
			$params['date_created']=date('c');
			$params['ip_address']=$_SERVER['REMOTE_ADDR'];
			if ( $res=AddonMobileApp::getDeviceID($this->data['registrationId'])){
				 $DbExt->updateData("{{mobile_registered}}",$params,'id',$res['id']);
				 
				 /*update all old device id of client to inactive*/
				 if(isset($params['client_id'])){
				   if(!empty($params['client_id'])){
				   	  $sql="UPDATE
	         			{{mobile_registered}}
	         			SET status='inactive'
	         			WHERE
	         			client_id=".self::q($params['client_id'])."
	         			AND
	         			device_id<>".self::q($params['device_id'])."
	         			";
	         		    $DbExt->qry($sql);
				   }
				 }
				 
			} else {
				$DbExt->insertData("{{mobile_registered}}",$params);			
			}		
			$this->code=1; $this->msg="OK";
		} else $this->msg="Empty registration id";
		$this->output();	
	}
	
	public function actionpaypalSuccessfullPayment()
	{		
		$DbExt=new DbExt;
				
		$resp=!empty($this->data['response'])?json_decode($this->data['response'],true):false;		
		if (AddonMobileApp::isArray($resp)){
			
			$order_id=isset($this->data['order_id'])?$this->data['order_id']:'';
			
			$params=array(
			  'payment_type'=>Yii::app()->functions->paymentCode("paypal"),
			  'payment_reference'=>$resp['response']['id'],
			  'order_id'=>$order_id,
			  'raw_response'=>$this->data['response'],
			  'date_created'=>date('c'),
			  'ip_address'=>$_SERVER['REMOTE_ADDR']
			);						
										
			if ( $DbExt->insertData("{{payment_order}}",$params) ){
				$this->code=1;
				$this->msg=Yii::t("default","Your order has been placed.");
	    	    $this->msg.=" ".AddonMobileApp::t("Reference # ".$order_id);
	    	    
	    	    $amount_to_pay=0;
	    	    $client_id='';
	    	    if($order_info=Yii::app()->functions->getOrderInfo($order_id)){
	    	       $amount_to_pay=$order_info['total_w_tax'];
	    	       $client_id=$order_info['client_id'];
	    	    }
	    	    
				$this->details=array(
				  'next_step'=>"receipt",
				  'amount_to_pay'=>$amount_to_pay
				);
				
				$params1=array('status'=> AddonMobileApp::t('paid') );		       
				$DbExt->updateData("{{order}}",$params1,'order_id',$order_id);
								
				/*insert logs for food history*/
				$params_logs=array(
				  'order_id'=>$order_id,
				  'status'=> 'paid',
				  'date_created'=>date('c'),
				  'ip_address'=>$_SERVER['REMOTE_ADDR']
				);
				$DbExt->insertData("{{order_history}}",$params_logs);
				
				// now we send the pending emails
				AddonMobileApp::processPendingReceiptEmail($order_id);
				
				/*pts*/
				if (AddonMobileApp::hasModuleAddon('pointsprogram')){
					if (getOptionA('points_enabled')==1){
					    AddonMobileApp::updatePoints($order_id,$client_id);
					}
				}
				
			} else $this->msg=$this->t("something went wrong during processing your request");
		} else $this->msg=$this->t("something went wrong during processing your request");
				
		$this->output();	
	}
	
	public function actionReverseGeoCoding()
	{		
		if (isset($this->data['lat']) && !empty($this->data['lng'])){
			$latlng=$this->data['lat'].",".$this->data['lng'];
			$file="https://maps.googleapis.com/maps/api/geocode/json?latlng=$latlng&sensor=true";
			$key=Yii::app()->functions->getOptionAdmin('google_geo_api_key');		
			if(!empty($key)){
				$file.="&key=".urlencode($key);
			}
			if ($res=@file_get_contents($file)){
				$res=json_decode($res,true);
				if (AddonMobileApp::isArray($res)){
					$this->code=1; $this->msg="OK";
					$this->details=$res['results'][0]['formatted_address'];
				} else  $this->msg=$this->t("not available");
			} else $this->msg=$this->t("not available");
		} else $this->msg=$this->t("missing coordinates");
		$this->output();
	}
	
	public function actionSaveSettings()
	{
		$DbExt=new DbExt;					
		if (!empty($this->data['device_id']) || $this->data['device_id']!="null"){
			$params=array(
			  'enabled_push'=>isset($this->data['enabled_push'])?1:2,
			  'date_modified'=>date('c'),
			  'ip_address'=>$_SERVER['REMOTE_ADDR'],
			  'country_code_set'=>isset($this->data['country_code_set'])?$this->data['country_code_set']:''
			);			
			
			$client_id='';
			if ( $client=AddonMobileApp::getClientTokenInfo($this->data['client_token'])) {				
				$client_id=$client['client_id'];
			}
			
			if ($client_id>0){
				$params['client_id']=$client_id;
			}			
			
			if ( $res=AddonMobileApp::getDeviceID($this->data['device_id'])){
				//update								
				if($DbExt->updateData("{{mobile_registered}}",$params,'device_id',$this->data['device_id'])){
					$this->code=1;
					$this->msg=$this->t("Setting saved");
				} else $this->msg=$this->t("something went wrong during processing your request");
			} else {
				//insert				
				$params['device_id']=$this->data['device_id'];
				$params['date_created']=date('c');
				if ($DbExt->insertData("{{mobile_registered}}",$params)){
					$this->code=1;
					$this->msg=$this->t("Setting saved");
				} else $this->msg=$this->t("something went wrong during processing your request");
			}		
		} else $this->msg=$this->t("missing device id");
		$this->output();
	}
	
	public function actionGetSettings()
	{				
		if (!empty($this->data['device_id']) || $this->data['device_id']!="null"){
			$device_id=$this->data['device_id'];			
			if ( $res=AddonMobileApp::getDeviceID($device_id)){				
				$this->code=1; $this->msg="OK";
				$this->details=$res;
			} else $this->msg=$this->t("settings not found");
		} else $this->msg=$this->t("missing device id");
		$this->output();
	}
	
	public function actionMobileCountryList()
	{
		$list=getOptionA('mobile_country_list');
		if (!empty($list)){
			$list=json_decode($list,true);			
		} else $list = array(
		  'US','PH','GB'
		);
		
		$country_code_set='';
		$device_id=isset($this->data['device_id'])?$this->data['device_id']:'';
		if ( $res=AddonMobileApp::getDeviceID($device_id)){				
			$country_code_set=$res['country_code_set'];
		}
		
		/*if (empty($country_code_set)){
			$country_code_set=getOptionA('merchant_default_country');
		}*/
		
		$new_list='';
		$c=require_once('CountryCode.php');
		if (AddonMobileApp::isArray($list)){
			foreach ($list as $val) {
				$new_list[$val]=$c[$val];
			}
		}	
				
		$this->code=1;
		$this->msg="OK";
		$this->details=array(
		  'selected'=>$country_code_set,
		  'list'=>$new_list
		);
		$this->output();
	}
	
	public function actionGetLanguageSettings()
	{		
		$mobile_dictionary=getOptionA('mobile_dictionary');
		$mobile_dictionary=!empty($mobile_dictionary)?json_decode($mobile_dictionary,true):false;
		if ( $mobile_dictionary!=false){
			$lang=$mobile_dictionary;
		} else $lang='';
		
		$mobile_default_lang='en';
		$default_language=getOptionA('default_language');
		if(!empty($default_language)){
			$mobile_default_lang=$default_language;
		}	
		
		$admin_decimal_separator=getOptionA('admin_decimal_separator');
		$admin_decimal_place=getOptionA('admin_decimal_place');
		$admin_currency_position=getOptionA('admin_currency_position');
		$admin_thousand_separator=getOptionA('admin_thousand_separator');
		
		$single_add_item=2;
		if (getOptionA('website_disbaled_auto_cart')=="yes"){
			$single_add_item=1;
		}
		
		/*pts*/
		$pts=1;
		if (AddonMobileApp::hasModuleAddon('pointsprogram')){
			if (getOptionA('points_enabled')==1){
			    $pts=2;
			}
		}
		
		/*facebook flag*/
		$facebook_flag=2;
		if (getOptionA('fb_flag')==1){
			$facebook_flag=1;
		}
		
		/*get profile pic*/
		$avatar=''; $client_name='';
		if(isset($this->data['client_token'])){
		  if(!empty($this->data['client_token'])){
			  if ( $client=AddonMobileApp::getClientTokenInfo($this->data['client_token'])) {
			  	 $client_name=$client['first_name'];
				 $avatar=AddonMobileApp::getAvatar( $client['client_id'] , $client );
			  }
		  }
		}
					
		if ( $mobile_default_lang=="en" || $mobile_default_lang=="-9999")
		{			
			$this->details=array(
			  'settings'=>array(
			    'decimal_place'=> !empty($admin_decimal_place)?$admin_decimal_place:2,
			    'currency_position'=>!empty($admin_currency_position)?$admin_currency_position:'left',
			    'currency_set'=>getCurrencyCode(),
			    'thousand_separator'=>!empty($admin_thousand_separator)?$admin_thousand_separator:'',
			    'decimal_separator'=>!empty($admin_decimal_separator)?$admin_decimal_separator:'.',
			    'single_add_item'=>$single_add_item,
			    'pts'=>$pts,
			    'facebook_flag'=>$facebook_flag,
			    'avatar'=>$avatar,
			    'client_name_cookie'=>$client_name
			  ),
			  'translation'=>$lang
			);
		} else {
			$this->details=array(
			  'settings'=>array(
			    'default_lang'=>$mobile_default_lang,
			    'decimal_place'=> !empty($admin_decimal_place)?$admin_decimal_place:2,
			    'currency_position'=>!empty($admin_currency_position)?$admin_currency_position:'left',
			    'currency_set'=>getCurrencyCode(),
			    'thousand_separator'=>!empty($admin_thousand_separator)?$admin_thousand_separator:'',
			    'decimal_separator'=>!empty($admin_decimal_separator)?$admin_decimal_separator:'.',	  
			    'single_add_item'=>$single_add_item ,
			    'pts'=>$pts,
			    'facebook_flag'=>$facebook_flag,
			    'avatar'=>$avatar,
			    'client_name_cookie'=>$client_name
			  ),
			  'translation'=>$lang
			);
		}
		
		$this->code=1;
		$this->output();
	}
	
	public function actionGetLanguageSelection()
	{
		if ($res=Yii::app()->functions->getLanguageList()){
			$set_lang_id=Yii::app()->functions->getOptionAdmin('set_lang_id');		
			//dump($set_lang_id);	
			//if (preg_match("/-9999/i", $set_lang_id)) {
				$eng[]=array(
				  'lang_id'=>"en",
				  'country_code'=>"US",
				  'language_code'=>"English"
				);
				$res=array_merge($eng,$res);
			//}						
			$this->code=1;
			$this->msg="OK";
			$this->details=$res;
		} else $this->msg=AddonMobileApp::t("no language available");
		$this->output();
	}
	
	public function actionApplyVoucher()
	{		
		
		if(isset($this->data['pts_redeem_amount'])){
		   if($this->data['pts_redeem_amount']>0){
		   	  $this->msg=$this->t("Sorry but you cannot apply voucher when you have already redeem a points");
		   	  $this->output();
		   	  Yii::app()->end();
		   }		
		}
		
		if ( $client=AddonMobileApp::getClientTokenInfo($this->data['client_token'])) {			
			$client_id=$client['client_id'];
			//dump($client_id);
			if (isset($this->data['merchant_id'])){
				$mtid=$this->data['merchant_id'];
				//dump($mtid);
				if ( $res=AddonMobileApp::getVoucherCodeNew($client_id,$this->data['voucher_code'],$mtid) ){
					//dump($res);
					
					/*check if voucher code can be used only once*/
					if ( $res['used_once']==2){
						if ( $res['number_used']>0){
							$this->msg=t("Sorry this voucher code has already been used");
							$this->output();
						}
					}
					
					if ( !empty($res['expiration'])){						
						$time_2=$res['expiration'];
       	  	            $time_2=date("Y-m-d",strtotime($time_2));	       	  	 
       	  	            $time_1=date('Y-m-d');	       	  	            
       	  	            $time_diff=Yii::app()->functions->dateDifference($time_2,$time_1);	       	  	            
       	  	            if (is_array($time_diff) && count($time_diff)>=1){
       	  	            	if($time_diff['days']>0){
       	  	            	  $this->msg=t("Voucher code has expired");
       	  	            	  $this->output();
       	  	            	}
       	  	            }
					}
					
					if ( $res['found']>0){
						$this->msg=Yii::t("default","Sorry but you have already use this voucher code");
						$this->output();
					}
					
					$less=''; $less_amount=0;
					if ($res['voucher_type']=="fixed amount"){
						$less=AddonMobileApp::prettyPrice($res['amount']);
						$less_amount=$res['amount'];
					} else {
						$less=standardPrettyFormat($res['amount'])."%";
						if($res['amount']>0.001){
						   $less_amount=($res['amount']/100);
						}
					}
					
					$total=0;
					$cart_sub_total=$this->data['cart_sub_total'];
					if($less_amount>0){
						if ($res['voucher_type']=="fixed amount"){		
							$cart_sub_total=$cart_sub_total-$less_amount;					
						} else {
							$less_amount=($cart_sub_total*$less_amount);
							$cart_sub_total=$cart_sub_total-$less_amount;
						}
					}
					
					if(isset($this->data['cart_delivery_charges'])){
					   $cart_sub_total+=unPrettyPrice($this->data['cart_delivery_charges']);
					}
					if(isset($this->data['cart_packaging'])){
					   $cart_sub_total+=unPrettyPrice($this->data['cart_packaging']);
					}
					
					if(isset($this->data['cart_tax'])){
					   if($this->data['cart_tax']>0){
					   	  $tax=$cart_sub_total*($this->data['cart_tax']/100);
					   	  $total=$cart_sub_total+$tax;
					   } else $total=$cart_sub_total;
					} else $total=$cart_sub_total;
						
					$voucher_details=array(
					  'voucher_id'=>$res['voucher_id'],
					  'voucher_name'=>$res['voucher_name'],
					  'voucher_type'=>$res['voucher_type'],
					  'amount'=>$res['amount'],
					  'less'=>$this->t("Less")." ".$less,
					  'new_total'=>$total
					);
					
					$this->details=$voucher_details;
					$this->code=1;
					$this->msg="merchant voucher";
					
				} else {
					// get admin voucher
					//echo 'get admin voucher';
					if ( $res=AddonMobileApp::getVoucherCodeAdmin($client_id,$this->data['voucher_code'])){
									

						if ( !empty($res['expiration'])){						
							$time_2=$res['expiration'];
	       	  	            $time_2=date("Y-m-d",strtotime($time_2));	       	  	 
	       	  	            $time_1=date('Y-m-d');	       	
	       	  	            	       	  	            
	       	  	            $time_diff=Yii::app()->functions->dateDifference($time_2,$time_1);	 
	       	  	            
	       	  	            if (is_array($time_diff) && count($time_diff)>=1){
	       	  	            	if($time_diff['days']>0){
		       	  	            	$this->msg=t("Voucher code has expired");
		       	  	            	$this->output();
	       	  	            	}
	       	  	            }						
						}
						
						/*check if voucher code can be used only once*/
						if ( $res['used_once']==2){
							if ( $res['number_used']>0){
								$this->msg=t("Sorry this voucher code has already been used");
								$this->output();
							}
						}
												
						if (!empty($res['joining_merchant'])){							
							$joining_merchant=json_decode($res['joining_merchant']);							
							if (in_array($this->data['merchant_id'],(array)$joining_merchant)){								
							} else {
								$this->msg=t("Sorry this voucher code cannot be used on this merchant");
								$this->output();
							}
						}
															
						if ( $res['found']>0){
							$this->msg=Yii::t("default","Sorry but you have already use this voucher code");
							$this->output();
						}
						
						$less='';
						$less_amount=0;
						if ($res['voucher_type']=="fixed amount"){
							$less=AddonMobileApp::prettyPrice($res['amount']);
							$less_amount=$res['amount'];
						} else {
							$less=standardPrettyFormat($res['amount'])."%";
							if($res['amount']>0.001){
							   $less_amount=($res['amount']/100);
							}
						}
						
						$total=0;
						$cart_sub_total=isset($this->data['cart_sub_total'])?$this->data['cart_sub_total']:0;
						if($less_amount>0){
							if ($res['voucher_type']=="fixed amount"){		
								$cart_sub_total=$cart_sub_total-$less_amount;
							} else {
								$less_amount=($cart_sub_total*$less_amount);
								$cart_sub_total=$cart_sub_total-$less_amount;
							}
						}
						
						if(isset($this->data['cart_delivery_charges'])){
						   $cart_sub_total+=unPrettyPrice($this->data['cart_delivery_charges']);
						}
						if(isset($this->data['cart_packaging'])){
						   $cart_sub_total+=unPrettyPrice($this->data['cart_packaging']);
						}
						
						if(isset($this->data['cart_tax'])){
						   if($this->data['cart_tax']>0){
						   	  $tax=$cart_sub_total*($this->data['cart_tax']/100);
						   	  $total=$cart_sub_total+$tax;
						   } else $total=$cart_sub_total;
						} else $total=$cart_sub_total;
						
						
						$voucher_details=array(
						  'voucher_id'=>$res['voucher_id'],
						  'voucher_name'=>$res['voucher_name'],
						  'voucher_type'=>$res['voucher_type'],
						  'amount'=>$res['amount'],
						  'less'=>$this->t("Less")." ".$less,
						  'new_total'=>$total
						);
						
						$this->details=$voucher_details;
						$this->code=1;
						$this->msg="admin voucher";
						
					} else $this->msg=Yii::t("default","Voucher code not found");
				}			
			} else $this->msg=$this->t("Merchant id is missing");		
		} else $this->msg=$this->t("invalid token");
		$this->output();
	}
	
	public function actionPayAtz()
	{
		
		if (!isset($this->data['merchant_id'])){
			$this->msg=$this->t("Merchant id is missing");
			$this->output();
			Yii::app()->end();
		}
		if(empty($this->data['expiration_month'])){
			$this->msg=$this->t("Expiration month is required");
			$this->output();
			Yii::app()->end();
		}
		if(empty($this->data['expiration_yr'])){
			$this->msg=$this->t("Expiration year is required");
			$this->output();
			Yii::app()->end();
		}
		if(empty($this->data['x_country'])){
			$this->msg=$this->t("Country is required");
			$this->output();
			Yii::app()->end();
		}
		if(empty($this->data['order_id'])){
			$this->msg=$this->t("Order id is missing");
			$this->output();
			Yii::app()->end();
		}
		
		if ( $resp=AddonMobileApp::getClientTokenInfo($this->data['client_token'])) {
			$client_id=$resp['client_id'];
			$mtid=$this->data['merchant_id'];
			$order_id=$this->data['order_id'];
			
			$mode_autho=Yii::app()->functions->getOption('merchant_mode_autho',$mtid);
            $autho_api_id=Yii::app()->functions->getOption('merchant_autho_api_id',$mtid);
            $autho_key=Yii::app()->functions->getOption('merchant_autho_key',$mtid);
            
            if ( Yii::app()->functions->isMerchantCommission($mtid)){			
				$mode_autho=Yii::app()->functions->getOptionAdmin('admin_mode_autho');
		        $autho_api_id=Yii::app()->functions->getOptionAdmin('admin_autho_api_id');
		        $autho_key=Yii::app()->functions->getOptionAdmin('admin_autho_key');        
			}
			
            if(empty($mode_autho) || empty($autho_api_id) || empty($autho_key)){
            	$this->msg=$this->t("Payment settings not properly configured");
			    $this->output();
		 	    Yii::app()->end();
            }
            
            define("AUTHORIZENET_API_LOGIN_ID",$autho_api_id); 
            define("AUTHORIZENET_TRANSACTION_KEY",$autho_key);
            define("AUTHORIZENET_SANDBOX",$mode_autho=="sandbox"?true:false);     
			
            $amount_to_pay=unPrettyPrice($this->data['total_w_tax']);
            
            require_once 'anet_php_sdk/AuthorizeNet.php';
            $transaction = new AuthorizeNetAIM;
            $transaction->setSandbox(AUTHORIZENET_SANDBOX);
            $params= array(		        
		        'description' => $this->data['paymet_desc'],
		        'amount'     => $amount_to_pay, 
		        'card_num'   => $this->data['cc_number'], 
		        'exp_date'   => $this->data['expiration_month']."/".$this->data['expiration_yr'],
		        'first_name' => $this->data['x_first_name'],
		        'last_name'  => $this->data['x_last_name'],
		        'address'    => $this->data['x_address'],
		        'city'       => $this->data['x_city'],
		        'state'      => $this->data['x_state'],
		        'country'    => $this->data['x_country'],
		        'zip'        => $this->data['x_zip'],
		        'card_code'  => $this->data['cvv'],
	        );
	        //dump($params);
	        //die();
	        $transaction->setFields($params);        
            $response = $transaction->authorizeAndCapture();
            if ($response->approved) {
            	$resp_transaction = $response->transaction_id;
            	//dump($resp_transaction);
            	
            	$db_ext=new DbExt;
            	
            	$params_update=array('status'=>'paid');	        
                $db_ext->updateData("{{order}}",$params_update,'order_id',$order_id);
                
            	$params_logs=array(
		          'order_id'=>$order_id,
		          'payment_type'=>Yii::app()->functions->paymentCode('authorize'),
		          'raw_response'=>json_encode($response),
		          'date_created'=>date('c'),
		          'ip_address'=>$_SERVER['REMOTE_ADDR'],
		          'payment_reference'=>$resp_transaction
		        );
		        $db_ext->insertData("{{payment_order}}",$params_logs);
		       
		        $this->code=1;
				$this->msg=Yii::t("default","Your order has been placed.");
	    	    $this->msg.=" ".AddonMobileApp::t("Reference # ".$order_id);
				$this->details=array(
				  'next_step'=>"receipt",
				  'amount_to_pay'=>$amount_to_pay
				);
				
				/*insert logs for food history*/
				$params_logs=array(
				  'order_id'=>$order_id,
				  'status'=> 'paid',
				  'date_created'=>date('c'),
				  'ip_address'=>$_SERVER['REMOTE_ADDR']
				);
				$db_ext->insertData("{{order_history}}",$params_logs);
								
			    // now we send the pending emails
				AddonMobileApp::processPendingReceiptEmail($order_id);
				
				/*pts*/
				if (AddonMobileApp::hasModuleAddon('pointsprogram')){
					if (getOptionA('points_enabled')==1){
					    AddonMobileApp::updatePoints($order_id,$client_id);
					}
				}
            	
             } else $this->msg=$response->response_reason_text;    	
		} else $this->msg=$this->t("invalid token");
		$this->output();
	}
	
	public function actionPayStp()
	{
		
		if (!isset($this->data['merchant_id'])){
			$this->msg=$this->t("Merchant id is missing");
			$this->output();
			Yii::app()->end();
		}
		if(empty($this->data['order_id'])){
			$this->msg=$this->t("Order id is missing");
			$this->output();
			Yii::app()->end();
		}
		if(empty($this->data['stripe_token'])){
			$this->msg=$this->t("Stripe token is missing");
			$this->output();
			Yii::app()->end();
		}
		if ( $resp=AddonMobileApp::getClientTokenInfo($this->data['client_token'])) {
			 
			$client_id=$resp['client_id'];
			$mtid=$this->data['merchant_id'];
			$order_id=$this->data['order_id'];
			
			if ( Yii::app()->functions->isMerchantCommission($mtid)){
			    $mode=Yii::app()->functions->getOptionAdmin('admin_stripe_mode');  
			    $mode=strtolower($mode);
			    if ( $mode=="sandbox"){
					$secret_key=Yii::app()->functions->getOptionAdmin('admin_sanbox_stripe_secret_key');   
					$publishable_key=Yii::app()->functions->getOptionAdmin('admin_sandbox_stripe_pub_key');   
				} elseif ($mode=="live"){
					$secret_key=Yii::app()->functions->getOptionAdmin('admin_live_stripe_secret_key');   
					$publishable_key=Yii::app()->functions->getOptionAdmin('admin_live_stripe_pub_key');   
				}	
			} else {
				$mode=Yii::app()->functions->getOption('stripe_mode',$mtid);   
				$mode=strtolower($mode);
				
				if ( $mode=="sandbox"){
					$secret_key=Yii::app()->functions->getOption('sanbox_stripe_secret_key',$mtid);   
					$publishable_key=Yii::app()->functions->getOption('sandbox_stripe_pub_key',$mtid);   
				} elseif ($mode=="live"){
					$secret_key=Yii::app()->functions->getOption('live_stripe_secret_key',$mtid);   
					$publishable_key=Yii::app()->functions->getOption('live_stripe_pub_key',$mtid);   
				}
			}		
			
			try {
				
				require_once('stripe/lib/Stripe.php');
				
				Stripe::setApiKey($secret_key);
				
			    $customer = Stripe_Customer::create(array(			    
			      'card'  => $this->data['stripe_token']
			    ));
			    
			    $amount_to_pay=unPrettyPrice($this->data['total_w_tax']);
			    $amount_to_pay_orig=$amount_to_pay;
			    $amount_to_pay=is_numeric($amount_to_pay)?unPrettyPrice($amount_to_pay*100):0;
		        $amount_to_pay=Yii::app()->functions->normalPrettyPrice2($amount_to_pay);	
		       
			    $charge = Stripe_Charge::create(array(
		          'customer' => $customer->id,
		          'amount'   => $amount_to_pay,
		          'currency' => Yii::app()->functions->adminCurrencyCode()
		        ));	        
		        
		        $chargeArray = $charge->__toArray(true);
		        
		        $db_ext=new DbExt;
		        $params_logs=array(
		          'order_id'=>$order_id,
		          'payment_type'=>"stp",
		          'raw_response'=>json_encode($chargeArray),
		          'date_created'=>date('c'),
		          'ip_address'=>$_SERVER['REMOTE_ADDR']
		        );
		        $db_ext->insertData("{{payment_order}}",$params_logs);
		        
		        $params_update=array( 'status'=>'paid');	        
		        $db_ext->updateData("{{order}}",$params_update,'order_id',$order_id);
		        
		        $this->code=1;
				$this->msg=Yii::t("default","Your order has been placed.");
	    	    $this->msg.=" ".AddonMobileApp::t("Reference # ".$order_id);
				$this->details=array(
				  'next_step'=>"receipt",
				  'amount_to_pay'=>$amount_to_pay_orig
				);
				
				/*insert logs for history*/
				$params_logs=array(
				  'order_id'=>$order_id,
				  'status'=> 'paid',
				  'date_created'=>date('c'),
				  'ip_address'=>$_SERVER['REMOTE_ADDR']
				);
				$db_ext->insertData("{{order_history}}",$params_logs);
				
				AddonMobileApp::processPendingReceiptEmail($order_id);
				
				/*pts*/
				if (AddonMobileApp::hasModuleAddon('pointsprogram')){
					if (getOptionA('points_enabled')==1){
					    AddonMobileApp::updatePoints($order_id,$client_id);
					}
				}
				
			} catch (Exception $e)   {
	    	   $this->msg=$e->getMessage();
	    }    
		} else $this->msg=$this->t("invalid token");
		$this->output();
	}

	
	public function actionValidateCLient()
	{
		$db_ext=new DbExt;  
		
		switch ($this->data['validation_type']) {
			case "mobile_verification":
				if ( $res=AddonMobileApp::verifyMobileCode($this->data['code'],$this->data['client_id'])){
				    
					$params=array( 
					  'status'=>"active",
					  'mobile_verification_date'=>date('c'),
					  'last_login'=>date('c')
					);
					$db_ext->updateData("{{client}}",$params,'client_id',$res['client_id']);
					$this->code=1;
					$this->msg=$this->t("Validation successful");
					$this->details=array(
					  'token'=>$res['token'],
					  'is_checkout'=>$this->data['is_checkout']
					);
					
				} else $this->msg=$this->t("verification code is invalid");
				break;
		
			case "email_verification":	
			    if( $res=Yii::app()->functions->getClientInfo( $this->data['client_id'] )){	
			    	if ($res['email_verification_code']==trim($this->data['code'])){
			    		
			    		$params=array( 
						  'status'=>"active",
						  'last_login'=>date('c')
						);
						$db_ext->updateData("{{client}}",$params,'client_id',$res['client_id']);
			    		
			    	 	$this->code=1;
					    $this->msg=$this->t("Validation successful");
					    $this->details=array(
						  'token'=>$res['token'],
						  'is_checkout'=>$this->data['is_checkout']
						);
					    
			    	} else $this->msg=$this->t("verification code is invalid");
			    } else $this->msg=$this->t("verification code is invalid");
				break;
				
			default:
				$this->msg=$this->t("validation type unrecognize");
				break;
		}
		
		$this->output();
	}
	
	public function actiongetPTS()
	{
		if ( $resp=AddonMobileApp::getClientTokenInfo($this->data['client_token'])) {
			 $client_id=$resp['client_id'];
			 $points=PointsProgram::getTotalEarnPoints($client_id);
			 $points_expiring=PointsProgram::getExpiringPoints($client_id);
			
			 $total_expenses_points=AddonMobileApp::getExpensesPointsTotal($client_id);
			 
			 $this->code=1;
			 $this->msg="OK";
			 $this->details=array(
			    'available_points'=>!empty($points)?$points:0,
			    'points_expiring'=>!empty($points_expiring)?$points_expiring:0,
			    'total_expenses_points'=>!empty($total_expenses_points)?$total_expenses_points:0,
			 );
		} else $this->msg=$this->t("invalid token");
		$this->output();
	}
	
	public function actiondetailsPTS()
	{
		$db_ext=new DbExt;  
		$feed_data=''; $title='';
		
		if ( $resp=AddonMobileApp::getClientTokenInfo($this->data['client_token'])) {
			$client_id=$resp['client_id'];			
			switch ($this->data['pts_type']) {
				case 1:
					$stmt="
					SELECT * FROM
					{{points_earn}}
					WHERE
					status='active'
					AND
					client_id=".Yii::app()->functions->q($client_id)."
					ORDER BY id DESC
					LIMIT 0,500
					";
					
					$title=$this->t("Income Points");
					break;
			
				case 2:	
				   $stmt="
					SELECT * FROM
					{{points_expenses}}
					WHERE
					status='active'
					AND
					client_id=".Yii::app()->functions->q($client_id)."
					ORDER BY id DESC
					LIMIT 0,500
					";
				   $title=$this->t("Expenses Points");
				   break;
				   
				case 3:
					$stmt="
					SELECT * FROM
					{{points_earn}}
					WHERE
					status='expired'
					AND
					client_id=".Yii::app()->functions->q($client_id)."
					ORDER BY id DESC
					LIMIT 0,500
					";
					$title=$this->t("Expired Points");
				   break;
			}			
			if ( $res=$db_ext->rst($stmt)){
				foreach ($res as $val) {
					$label=PointsProgram::PointsDefinition($val['points_type'],$val['trans_type'],
					$val['order_id'],$val['total_points']);					
					
					$points=$val['total_points_earn'];
					$points_label="<span>+".$points."</span>";
					if($this->data['pts_type']==2){
						$points=$val['total_points'];
						$points_label="<span>-".$points."</span>";
					}					
					
					$feed_data[]=array(
					   'date_created'=>Yii::app()->functions->displayDate($val['date_created']),
					   "label"=>$label,
					   "points"=>$points_label
					);
				}
			} 
			
			$this->code=1;
			$this->msg="OK";
			$this->details=array(
			  'title'=>$title,
			  'data'=>$feed_data
			);
		} else $this->msg=$this->t("invalid token");
		$this->output();
	}
	
	public function actionapplyRedeemPoints()
	{

	    $Validator=new Validator;
	    
	    $amt=0; $total=0;
	    
	    if(isset($this->data['subtotal_order'])){
	    	$this->data['subtotal_order']=trim($this->data['subtotal_order']);
	    }
	    
	    $req=array(
	      'redeem_points'=>AddonMobileApp::t("redeem points is required"),
	      'subtotal_order'=>$this->t("Subtotal is missing")
	    );
	    	    
	    if($this->data['voucher_amount']>0.0){
	        $Validator->msg[]=AddonMobileApp::t("Sorry but you cannot redeem points if you have already voucher applied on your cart");
	    }
	    if ( $this->data['redeem_points']<1){
	    	$Validator->msg[]=AddonMobileApp::t("Redeem points must be greater than zero");
	    }
	    if ( !$resp=AddonMobileApp::getClientTokenInfo($this->data['client_token'])) {
	    	$this->msg[]=AddonMobileApp::t("invalid token");
	    } else {
	    	
	    	 $client_id=$resp['client_id'];
	    	 $balance_points=PointsProgram::getTotalEarnPoints($client_id);	
	    	 
	    	 if ( $balance_points<$this->data['redeem_points']){
	    		$Validator->msg[]=$this->t("Sorry but your points is not enough");
	    	 }
	    	 
	    	$points_apply_order_amt=PointsProgram::getOptionA('points_apply_order_amt');
			if ($points_apply_order_amt>0){
				if ( $points_apply_order_amt>$this->data['subtotal_order'] ){
					$Validator->msg[]=AddonMobileApp::t("Sorry but you can only redeem points on orders over")." ".
					Yii::app()->functions->normalPrettyPrice($points_apply_order_amt);
				}
			}
			
			$points_minimum=PointsProgram::getOptionA('points_minimum');		
			if ($points_minimum>0){
				if ( $points_minimum>$this->data['redeem_points']){
					$Validator->msg[]=PointsProgram::t("Sorry but Minimum redeem points can be used is")." ".$points_minimum;	    
				}
			}
			
			$points_max=PointsProgram::getOptionA('points_max');
			if ( $points_max>0){
				if ( $points_max<$this->data['redeem_points']){
					$Validator->msg[]=PointsProgram::t("Sorry but Maximum redeem points can be used is")." ".$points_max;
				}
			}
			
			/*convert the redeem points to amount value*/
			$pts_redeeming_point=PointsProgram::getOptionA('pts_redeeming_point');
			$pts_redeeming_point_value=PointsProgram::getOptionA('pts_redeeming_point_value');
			if ($pts_redeeming_point<0.01){							
				$Validator->msg[]=PointsProgram::t("Error Redeeming Point less than zero on the backend settings");
			} 
			
			if ($pts_redeeming_point_value<0.01){				
				$Validator->msg[]=PointsProgram::t("Error Redeeming Point value is less than zero on the backend settings");	
				$this->jsonResponse();
				Yii::app()->end();
			}
			
			//$amt=($this->data['redeem_points']/$pts_redeeming_point)*$pts_redeeming_point_value;
			$temp_redeem=intval($this->data['redeem_points']/$pts_redeeming_point);
			$amt=$temp_redeem*$pts_redeeming_point_value;
			$amt=Yii::app()->functions->normalPrettyPrice($amt);
			
	    } /*end if*/
	    
	    $Validator->required($req,$this->data);
		if ($Validator->validate()){
			$client_id=$resp['client_id'];	
			
			$cart_sub_total=$this->data['cart_sub_total']-$amt;
			
			if(isset($this->data['cart_delivery_charges'])){
			   $cart_sub_total+=unPrettyPrice($this->data['cart_delivery_charges']);
			}
			if(isset($this->data['cart_packaging'])){
			   $cart_sub_total+=unPrettyPrice($this->data['cart_packaging']);
			}
			
			if(isset($this->data['cart_tax'])){
			   if($this->data['cart_tax']>0){
			   	  $tax=$cart_sub_total*($this->data['cart_tax']/100);
			   	  $total=$cart_sub_total+$tax;
			   } else $total=$cart_sub_total;
			} else $total=$cart_sub_total;
			
			$this->code=1;
			$this->msg="OK";
			$this->details=array(			  
			  'pts_amount'=>AddonMobileApp::prettyPrice($amt),
			  'pts_amount_raw'=>$amt,
			  'pts_points'=>$this->data['redeem_points']." ".AddonMobileApp::t("Points"),
			  'pts_points_raw'=>$this->data['redeem_points'],
			  'new_total'=>$total
			);
			
			
		} else $this->msg=AddonMobileApp::parseValidatorError($Validator->getError());
		$this->output();
	}
	
} /*end class*/