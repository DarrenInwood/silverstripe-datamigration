<form method="POST" enctype="multipart/form-data">
	<h3>Data Migration</h3>
	<p>Paste in the list of ID|Classname pairs to export, as output by a database select, like:</p>
	<pre>
+------+-----------------+
| ID   | ClassName       |
+------+-----------------+
| 2218 | NowShowingEvent |
| 2217 | NowShowingEvent |
| 2216 | NowShowingEvent |
| 2207 | NowShowingEvent |
| 2206 | NowShowingEvent |
| 2205 | NewsPage        |
| 2204 | NewsPage        |
| 2202 | NewsPage        |
| 2197 | NewsPage        |
| 2196 | NowShowingEvent |
+------+-----------------+
10 rows in set (0.00 sec)
	</pre>
	<p>This will generate a file that can be imported at /datamigration/import
	on the external site, assuming the same classes are set up.</p>
	<p>
		<label for="data" style="display: none;">Data</label>
		<textarea id="data" name="data" rows="20" cols="80"></textarea>
	</p>
	<input type="submit">
</form>
