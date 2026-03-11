<?php if (!defined('HTMLY')) die('HTMLy'); ?>
<!DOCTYPE html>
<html lang="<?php echo blog_language();?>">
<head>
    <?php echo head_contents();?>
    <?php echo $metatags;?>
    <!-- Bootstrap Core CSS -->
    <link href="<?php echo theme_path();?>css/bootstrap.min.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="<?php echo theme_path();?>css/clean-blog.css" rel="stylesheet">
    <!-- Custom Fonts -->
    <link rel="stylesheet" href="<?php echo site_url() ?>system/resources/css/fontawesome.min.css?v=1">
    <link rel="stylesheet" href="<?php echo site_url() ?>system/resources/css/solid.min.css">
    <link rel="stylesheet" href="<?php echo site_url() ?>system/resources/css/brands.min.css">
    <link rel="stylesheet" id="cleanblog-lora" href="<?php echo theme_path();?>css/lora.css" type="text/css" media="all">
    <link rel="stylesheet" id="cleanblog-open-sans" href="<?php echo site_url();?>system/resources/css/open-sans.css" type="text/css" media="all">
</head>
<body>
<?php if (facebook()) { echo facebook(); } ?>
<?php if (login()) { toolbar(); } ?>
    <!-- Navigation -->
    <nav class="navbar navbar-default navbar-custom navbar-fixed-top">
        <div class="container-fluid">
            <!-- Brand and toggle get grouped for better mobile display -->
            <div class="navbar-header page-scroll">
                <button type="button" class="navbar-toggle" data-toggle="collapse" data-target="#bs-example-navbar-collapse-1">
                    <span class="sr-only">Toggle navigation</span>
                    <span class="icon-bar"></span>
                    <span class="icon-bar"></span>
                    <span class="icon-bar"></span>
                </button>
                <a class="navbar-brand" href="<?php echo site_url();?>"><?php echo blog_title();?></a>
            </div>
            <!-- Collect the nav links, forms, and other content for toggling -->
            <div class="collapse navbar-collapse" id="bs-example-navbar-collapse-1">
                <?php echo menu('navbar-nav navbar-right') ?>
            </div>
            <!-- /.navbar-collapse -->
        </div>
        <!-- /.container -->
    </nav>
    <!-- Page Header -->
    <!-- Set your background image for this header on the line below. -->
    <header class="intro-header">
        <div class="container">
            <div class="row">
                <div class="col-lg-8 col-lg-offset-2 col-md-10 col-md-offset-1">
                    <?php if (isset($is_front)):?>
                        <div class="site-heading">
                            <h1><?php echo blog_title();?></h1>
                            <hr class="small">
                            <span class="subheading"><?php echo blog_tagline();?></span>
                        </div>
                        <style>
                        <?php if(empty(theme_config('cleanblog_homebg'))):?>
                            .intro-header {background-image: url('<?php echo theme_path();?>img/home-bg.jpg')}
                        <?php else: ?>
                            .intro-header {background-image: url('<?php echo theme_config('cleanblog_homebg');?>')}
                        <?php endif; ?>
                        </style>
                    <?php endif;?>
                    <?php if (isset($is_post)):?>
                        <div class="post-heading">
                            <h1><?php echo $p->title;?></h1>
                            <span class="meta"><?php echo i18n('Posted_in');?> <?php echo $p->category;?> <?php echo i18n('by');?> <a href="<?php echo $p->authorUrl;?>"><?php echo $p->authorName;?></a> - <?php echo format_date($p->date); ?></span>
                        </div>
                        <style>
                        <?php if(empty($p->image)):?>
                            .intro-header {background-image: url('<?php echo theme_path();?>img/post-bg.jpg')}
                        <?php else: ?>
                            .intro-header {background-image: url('<?php echo $p->image;?>')}
                        <?php endif; ?>
                        </style>
                    <?php endif;?>
                    <?php if (isset($is_page) || isset($is_subpage)):?>
                        <div class="page-heading">
                            <h1><?php echo $p->title;?></h1>
                            <hr class="small">
                            <span class="subheading"><?php echo blog_title();?></span>
                        </div>
                        <style>
                        <?php if(empty(theme_config('cleanblog_pagebg'))):?>
                            .intro-header {background-image: url('<?php echo theme_path();?>img/home-bg.jpg')}
                        <?php else: ?>
                            .intro-header {background-image: url('<?php echo theme_config('cleanblog_pagebg');?>')}
                        <?php endif; ?>
                        </style>
                    <?php endif;?>
                    <?php if (isset($is_profile)):?>
                        <div class="page-heading">
                            <h1><?php echo $name ?></h1>
                            <hr class="small">
                            <span class="subheading"><?php echo blog_title();?></span>
                        </div>
                        <style>
                        <?php if(empty(theme_config('cleanblog_profilebg'))) :?>
                            .intro-header {background-image: url('<?php echo theme_path();?>img/about-bg.jpg')}
                        <?php else: ?>
                            .intro-header {background-image: url('<?php echo theme_config('cleanblog_profilebg');?>')}
                        <?php endif; ?>
                        </style>
                    <?php endif;?>
                    <?php if (isset($is_blog)):?>
                        <div class="page-heading">
                            <h1>Blog</h1>
                            <hr class="small">
                            <span class="subheading"><?php echo blog_title();?></span>
                        </div>
                        <style>
                        <?php if(empty(theme_config('cleanblog_blogbg'))) :?>
                            .intro-header {background-image: url('<?php echo theme_path();?>img/post-bg.jpg')}
                        <?php else: ?>
                            .intro-header {background-image: url('<?php echo theme_config('cleanblog_blogbg');?>')}
                        <?php endif; ?>
                        </style>
                    <?php endif;?>
                    <?php if (isset($is_category)):?>
                        <div class="page-heading">
                            <h1><?php echo $category->title;?></h1>
                            <hr class="small">
                            <span class="subheading"><?php echo blog_title();?></span>
                        </div>
                        <style>
                        <?php if(empty(theme_config('cleanblog_categorybg'))):?>
                            .intro-header {background-image: url('<?php echo theme_path();?>img/home-bg.jpg')}
                        <?php else: ?>
                            .intro-header {background-image: url('<?php echo theme_config('cleanblog_categorybg');?>')}
                        <?php endif; ?>
                        </style>
                    <?php endif;?>
                    <?php if (isset($is_tag)):?>
                        <div class="site-heading">
                            <h1><?php echo $tag->title;?></h1>
                            <hr class="small">
                            <span class="subheading"><?php echo blog_title();?></span>
                        </div>
                        <style>
                        <?php if(empty(theme_config('cleanblog_tagbg'))) :?>
                            .intro-header {background-image: url('<?php echo theme_path();?>img/home-bg.jpg')}
                        <?php else: ?>
                            .intro-header {background-image: url('<?php echo theme_config('cleanblog_tagbg');?>')}
                        <?php endif; ?>
                        </style>
                    <?php endif;?>
                    <?php if (isset($is_archive)):?>
                        <div class="site-heading">
                            <h1><?php echo $archive->title;?></h1>
                            <hr class="small">
                            <span class="subheading"><?php echo blog_title();?></span>
                        </div>
                        <style>
                        <?php if(empty(theme_config('cleanblog_archivebg'))) :?>
                            .intro-header {background-image: url('<?php echo theme_path();?>img/home-bg.jpg')}
                        <?php else: ?>
                            .intro-header {background-image: url('<?php echo theme_config('cleanblog_archivebg');?>')}
                        <?php endif; ?>
                        </style>
                    <?php endif;?>
                    <?php if (isset($is_search)):?>
                        <div class="site-heading">
                            <h1><?php echo $search->title;?></h1>
                            <hr class="small">
                            <span class="subheading"><?php echo blog_title();?></span>
                        </div>
                        <style>
                        <?php if(empty(theme_config('cleanblog_searchbg'))) :?>
                            .intro-header {background-image: url('<?php echo theme_path();?>img/home-bg.jpg')}
                        <?php else: ?>
                            .intro-header {background-image: url('<?php echo theme_config('cleanblog_searchbg');?>')}
                        <?php endif; ?>
                        </style>
                    <?php endif;?>
                    <?php if (isset($is_404) || isset($is_404search)):?>
                        <div class="site-heading">
                            <h1>Error 404!</h1>
                            <hr class="small">
                            <span class="subheading"><?php echo blog_tagline();?></span>
                        </div>
                        <style>
                        <?php if(empty(theme_config('cleanblog_404bg'))) :?>
                            .intro-header {background-image: url('<?php echo theme_path();?>img/home-bg.jpg')}
                        <?php else: ?>
                            .intro-header {background-image: url('<?php echo theme_config('cleanblog_404bg');?>')}
                        <?php endif; ?>
                        </style>
                    <?php endif;?>
                    <?php if (!empty($breadcrumb)): ?>
                        <div class="breadcrumb <?php if (isset($is_post)):?>left<?php endif;?>"><?php echo $breadcrumb ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>
    <?php echo content();?>
    <hr>
    <!-- Footer -->
    <footer>
        <div class="container">
            <div class="row">
                <div class="col-lg-8 col-lg-offset-2 col-md-10 col-md-offset-1">
                    <?php echo social('text-center');?>
                    <div class="copyright text-muted"><?php echo copyright();?></div>
                </div>
            </div>
        </div>
    </footer>
    <!-- jQuery -->
    <script src="<?php echo theme_path();?>js/jquery.min.js"></script>
    <!-- Bootstrap Core JavaScript -->
    <script src="<?php echo theme_path();?>js/bootstrap.min.js"></script>
    <!-- Custom Theme JavaScript -->
    <script src="<?php echo theme_path();?>js/clean-blog.js"></script>
    <?php if (analytics()): ?><?php echo analytics() ?><?php endif; ?>
</body>
</html>
