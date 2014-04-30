<?php
/*---------------------------------------------\
|											   |
| @Author:       Andrey Brykin (Drunya)        |
| @Email:        drunyacoder@gmail.com         |
| @Site:         http://atomx.net              |
| @Version:      1.0                           |
| @Project:      CMS                           |
| @Package       AtomX CMS                     |
| @Subpackege    Json_encode filter            |
| @Copyright     ©Andrey Brykin 2010-2014      |
| @Last mod      2014/04/30                    |
|----------------------------------------------|
|											   |
| any partial or not partial extension         |
| CMS Fapos,without the consent of the         |
| author, is illegal                           |
|----------------------------------------------|
| Любое распространение                        |
| CMS Fapos или ее частей,                     |
| без согласия автора, является не законным    |
\---------------------------------------------*/

class Fps_Viewer_Filter_JsonEncode {


	public function compile($value, Fps_Viewer_CompileParser $compiler)
	{
        if (!is_callable($value)) throw new Exception('(Filter_Json_encode):Value for filtering must be callable.');

        $compiler->raw('json_encode(');
        $value($compiler);
        $compiler->raw(', JSON_FORCE_OBJECT)');
	}
	
	
	public function __toString()
	{
		$out = '[filter]:json_encode' . "\n";
		return $out;
	}
}