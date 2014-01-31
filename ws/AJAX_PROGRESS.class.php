<?php
  class AJAX_PROGRESS
  {
    private $template;

    function __construct()
    {
      $this->template="<script type='text/javascript'>window.top.mk_update_progress(%s,'%s');</script>";
      $this->write(str_pad('<HTML><BODY>',4096)); //force browser to render
    }

    private function write($a)
    {
      echo $a;
      ob_flush();
      flush();
    }

    function advance($perc,$msg='')
    {
     $this->write(sprintf($this->template,$perc,$msg));
    }

    function __destruct()
    {
     $this->advance(-1);
     $this->write('</BODY></HTML>');
    }
  } // AJAX_PROGRESS
?>