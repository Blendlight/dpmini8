<?php

class Moin
{
    static function pre($var, $attrs=[])
    {
        $attribs = "";
        foreach($attrs as $key=>$value)
        {
            $attribs .= " $key=\"$value\" ";
        }

        echo "<pre $attribs>";
        print_r($var);
        echo '</pre>';
    }

    static function showClassMethods($class)
    {
        $methods =  get_class_methods ( $class );
        static::pre($methods);
    }

    static  function preColor($var, $col = NULL)
    {
        if($col == NULL)
        {
            $col = self::random_color();
        }

        static::pre($var, ["style"=>"background:$col;padding:5px;color:white;border:2px solid black;margin:5px;box-sizing:border-box;white-space: pre-wrap;"]);
    }


    static function random_color_part() {
        return str_pad( dechex( mt_rand( 0, 100 ) ), 2, '0', STR_PAD_LEFT);
    }

    static function random_color() {
        return "#".static::random_color_part() . static::random_color_part() . static::random_color_part();
    }

}