<?php
/* Author: Romain Dal Maso <artefact2@gmail.com>
 *
 * This program is free software. It comes without any warranty, to the
 * extent permitted by applicable law. You can redistribute it and/or
 * modify it under the terms of the Do What The Fuck You Want To Public
 * License, Version 2, as published by Sam Hocevar. See
 * http://sam.zoy.org/wtfpl/COPYING for more details. */

function tsv_perf(array $pf, $stream, $start, $end, $absolute = true, array $overlays = []) {	
	$start = strtotime(date('Y-m-d', maybe_strtotime($start)));
	$end = strtotime(date('Y-m-d', maybe_strtotime($end)));

	foreach($overlays as $ol) {
		if(!isset($pf['lines'][$ol])) {
			fatal("Overlay must be a valid ticker: %s\n", $ol);
		}
	}

	if($absolute && $overlays !== []) {
		fatal("Overlays not available in absolute mode\n");
	}
	
	$txs = $pf['tx'];
	reset($txs);
	$tx = current($txs);
	$in = 0;

	$hold = [];
	$obases = [];
	
	while($start <= $end) {
		while($tx !== false && $tx['ts'] <= $start) {
			if(!isset($hold[$tx['ticker']])) {
				$hold[$tx['ticker']] = 0;
			}

			$hold[$tx['ticker']] += $tx['buy'];
			$in += ($deltain = $tx['fee'] + $tx['buy']*$tx['price']);
			$tx = next($txs);

			if(isset($firstv)) $firstv += $deltain;
		}

		$value = 0;
		foreach($hold as $ticker => $qty) {
			$value += get_quote($pf, $ticker, $start) * $qty;
		}

		if($absolute) {
			fprintf(
				$stream,
				"%s\t%f\t%f\n",
				date('Y-m-d', $start),
				$in,
				$value - $in
			);
		} else {
			$ovals = [];
			
			if(!isset($firstv)) {
				$firstv = $value > 0 ? $value : $in;
				$v = 0.0;

				foreach($overlays as $t) {
					$obases[$t] = get_quote($pf, $t, $start);
					$ovals[$t] = 100.0;
				}
			} else if($firstv > 0) {
				$v = 100.0 * ($value - $firstv) / $firstv;
				foreach($overlays as $t) $ovals[$t] = 100.0 * get_quote($pf, $t, $start) / $obases[$t];
			} else {
				$v = 0.0;

				foreach($overlays as $t) $ovals[$t] = 100.0;
			}
			
			fprintf(
				$stream,
				"%s\t%f\t%f",
				date('Y-m-d', $start),
				100,
				$v
			);

			foreach($overlays as $t) fprintf($stream, "\t%f", $ovals[$t]);

			fwrite($stream, "\n");
		}
	    
		$start = strtotime('+1 day', $start);
	}
}

function plot_perf(array $pf, $start, $end, $absolute = true, array $overlays = []) {
	$dat = tempnam(sys_get_temp_dir(), 'pfm');
	tsv_perf($pf, $datf = fopen($dat, 'wb'), $start, $end, $absolute, $overlays);
	fclose($datf);
	
	$sf = popen('gnuplot -p', 'wb');
	fwrite($sf, "set xdata time\n");
	fwrite($sf, "set timefmt '%Y-%m-%d'\n");
	fprintf($sf, "set xrange ['%s':'%s']\n", date('Y-m-d', maybe_strtotime($start)), date('Y-m-d', maybe_strtotime($end)));
	fwrite($sf, "set style fill solid 0.5 noborder\n");
	fwrite($sf, "set grid xtics\n");
	fwrite($sf, "set grid ytics\n");
	fwrite($sf, "set grid mytics\n");
	fwrite($sf, "set mytics 2\n");
	fwrite($sf, "set xtics format '%Y-%m-%d' rotate by -90\n");
	fwrite($sf, "show grid\n");
	fprintf(
		$sf,
		"plot '%s' using 1:(\$2+\$3):2 with filledcurves above linecolor '#008000' title 'Gains', '%s' using 1:(\$2+\$3):2 with filledcurves below linecolor '#800000' title 'Losses'",
		$dat,
		$dat
	);

	$i = 4;
	foreach($overlays as $o) {
		fprintf(
			$sf,
			", '%s' using 1:$i with lines linecolor '#000000' title '%s'",
			$dat,
			$o
		);
		++$i;
		/* TODO: colorize overlay lines */
	}

	fwrite($sf, "\n");
	fclose($sf);
	unlink($dat);
}