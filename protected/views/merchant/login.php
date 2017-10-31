<div class="merchant-body">
  

<div class="merchant-panel login_wrap">
<div class="merchant-left">
    <div class="welcome-text">
        <h3 class="main-title">Welcome Merchant</h3>
        <p class="content">
          Hope You Are Doing Well

          <br>
          <br>
          <br>
          
        </p>
          <p class="content sContent">Welcome to the platform where now you can make your business online and reach your customer more easily.<br>
          Just Login & Add All About Your Cuisine Clan to Manage</p>
        <p class="right-arrow"></p>
    </div> 
</div>
<div class="merchant-right">
    <div class="login_logo"></div>
    <div class="uk-panel mlogin-box uk-panel-box uk-width-* merchant-input">

   <?php $name=Yii::app()->functions->getOptionAdmin('website_title');?>
   
   <h3 class="uk-h3"><?php echo !empty($name)?$name:"Foodoku";?> <?php echo Yii::t("default","Merchant Administration")?></h3>   
     
   <form id="forms" class="uk-form forms" onsubmit="return false;" method="POST">   
   <?php echo CHtml::hiddenField("action",'merchantLogin')?>
   <?php echo CHtml::hiddenField("redirect",Yii::app()->request->baseUrl."/merchant")?>
   
   
   <?php if (isset($_GET['message'])):?>
   <p class="uk-text-danger"><?php echo $_GET['message']?></p>
   <?php endif;?>
   
   <div class="uk-form-row form-control">
      <div class="uk-form-icon uk-width-1">
        <i class="uk-icon-user"></i>
       <?php echo CHtml::textField('username','',array('class'=>"uk-width-1",'placeholder'=>Yii::t("default","Username"),
       'data-validation'=>"required"
       ));?>
      </div>
   </div>   
   <div class="uk-form-row form-control">     
       <div class="uk-form-icon uk-width-1">
        <i class="uk-icon-lock"></i>
        <?php echo CHtml::passwordField('password','',array('class'=>"uk-width-1",'placeholder'=>Yii::t("default","Password"),
        'data-validation'=>"required"
        ));?>
       </div>     
   </div>        
   
   <?php if (getOptionA('captcha_merchant_login')==2):?>
   <?php GoogleCaptcha::displayCaptcha()?>
   <?php endif;?>
   
   <div class="uk-form-row ">   
   <button id="mSignIn" class="uk-button uk-width-1"><?php echo Yii::t("default","Sign In")?> <i class="uk-icon-chevron-circle-right"></i></button>
   </div>
   
   <p><a href="javascript:;" class="mt-fp-link"><?php echo Yii::t("default","FORGOT PASSWORD")?>?</a></p>
   
   </form>
   
   <form id="mt-frm" class="uk-form mt-frm" onsubmit="return false;" method="POST">   
   <?php echo CHtml::hiddenField("action",'merchantForgotPass')?>
   <h4><?php echo Yii::t("default","Forgot Password")?></h4>
   
   <div class="uk-form-row form-control">
      <div class="uk-form-icon uk-width-1">
        <i class="uk-icon-envelope"></i>
       <?php echo CHtml::textField('email_address','',array('class'=>"uk-width-1",'placeholder'=>Yii::t("default","Email address"),
       'data-validation'=>"required"
       ));?>
      </div>
   </div>   
      
   <div class="uk-form-row">   
   <button id="mSignIn" class="uk-button uk-width-1"><?php echo Yii::t("default","Submit")?> <i class="uk-icon-chevron-circle-right"></i></button>
   </div>
   
   <p><a href="javascript:;" class="mt-login-link"><?php echo Yii::t("default","Login")?></a></p>
   
   </form>
   
   
   <form id="mt-frm-activation" class="uk-form mt-frm-activation" onsubmit="return false;" method="POST">   
   <?php echo CHtml::hiddenField("action",'merchantChangePassword')?>
   <?php echo CHtml::hiddenField("email",'')?>
   <h4><?php echo Yii::t("default","Enter Verification Code & Your New Password")?></h4>
   
   <div class="uk-form-row">
      <div class="uk-form-icon uk-width-1">
        <i class="uk-icon-unlock"></i>
       <?php echo CHtml::textField('lost_password_code','',array('class'=>"uk-width-1",'placeholder'=>Yii::t("default","Code"),
       'data-validation'=>"required"
       ));?>
      </div>
   </div>   
   
   <div class="uk-form-row">  
      <div class="uk-form-icon uk-width-1">
        <i class="uk-icon-lock"></i>
       <?php echo CHtml::passwordField('new_password','',array('class'=>"uk-width-1",'placeholder'=>Yii::t("default","New Password"),
       'data-validation'=>"required"
       ));?>
      </div>
   </div>   
    
   <div class="uk-form-row">   
   <button class="uk-button uk-width-1"><?php echo Yii::t("default","Submit")?> <i class="uk-icon-chevron-circle-right"></i></button>
   </div>
    
   <p><a href="javascript:;" class="mt-login-link"><?php echo Yii::t("default","Login")?></a></p>
   
   </form>
   
   
</div>
</div>

</div> <!--END login_wrap-->

</div> <!-- body class end -->