<?php

/** 
 *	(c) 2017 uzERP LLP (support#uzerp.com). All rights reserved. 
 * 
 *	Released under GPLv3 license; see LICENSE. 
 **/

class HourTypeCollection extends DataObjectCollection
{
	
	protected $version = '$Revision: 1.5 $';
	
	public function __construct($do = 'HourType')
	{
		parent::__construct($do);
	}

}

// End of HourTypeCollection
