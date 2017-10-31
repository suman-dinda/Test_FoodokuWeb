<?php $this->renderPartial('/layouts/header');?>

<body>

<a class="pad5 block" href="<?php echo websiteUrl()."/admin"?>"><i class="fa fa-long-arrow-left"></i> Back</a>

<div class="container" id="main-wrapper">
  <div class="panel panel-default">
     <div class="panel-heading"><?php echo t("Foodoku Mobile App Modules")?></div>     
     <?php $this->renderPartial('/layouts/menu');?>
  
     <?php echo $content?>  
    
   </div> <!--panel-->
</div> <!--container-->
</body>

<?php $this->renderPartial('/layouts/footer');?>