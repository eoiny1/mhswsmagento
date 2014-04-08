package com.mhsystems.gen2rmsstack.test;

import java.io.BufferedReader;
import java.io.InputStreamReader;
import java.net.URL;
import java.net.URLEncoder;

import com.mhsystems.gen2rmsstack.repackaged.json.JSONArray;
import com.mhsystems.gen2rmsstack.repackaged.json.JSONObject;

public class Main {
	
	private static final String KEYWORD = "ilovetoronto";
	
	private static final String ACCESS_IDENTIFIER = "TBD";
	private static final String SECRET_WORD = "TBD";
	
	public static final void main(final String [] args) throws Exception {
		
		final String context_productcache = Main.buildCache();
		
		Main.downloadCache(context_productcache);
		
		// some time later ... this example downloads the inventory cache, this is how you would do it ...
		
		/*
		final String context_inventory = Main.buildCache();
		
		Main.downloadQtyOnly(context_inventory);
		
		final JSONArray items = new JSONArray();
		
		items.put((new JSONObject()).put("4611686018428496915L", 1));
		items.put((new JSONObject()).put("4611686018443573690L", 1));
		
		Main.checkout(items, "TRX 101");
		*/
		
	}
	
	private static final JSONObject checkout(final JSONArray items, final String reference) throws Exception {
		
		final StringBuilder url = new StringBuilder();
		
		url.append("https://mhswsgen2rmsstackapi.appspot.com/checkout");
		url.append("?keyword=");
		url.append(KEYWORD);
		url.append("&client_identifier=");
		url.append(ACCESS_IDENTIFIER);
		url.append("&reference=");
		url.append(URLEncoder.encode(reference, "UTF-8"));
		url.append("&items=");
		url.append(URLEncoder.encode(items.toString(), "UTF-8"));
		
		url.append("&signature=");
		url.append(MessageTokenUtil.computeMD5(SECRET_WORD + KEYWORD));
		
		return Main.fetch(url.toString());
		
	}
	
	private static final JSONObject downloadBatch(final String context, final String cursor) throws Exception {
		
		final StringBuilder url = new StringBuilder();
		
		url.append("https://mhswsgen2rmsstackapi.appspot.com/cache/downloadproduct");
		url.append("?keyword=");
		url.append(KEYWORD);
		url.append("&client_identifier=");
		url.append(ACCESS_IDENTIFIER);
		url.append("&limit=");
		url.append("128");
		url.append("&context=");
		url.append(context);
		
		if (cursor != null) {
			url.append("&cursor=");
			url.append(cursor);
		}
		
		url.append("&signature=");
		url.append(MessageTokenUtil.computeMD5(SECRET_WORD + KEYWORD));
		
		return Main.fetch(url.toString());
		
	}
	
	private static final JSONObject downloadQtyOnlyBatch(final String context, final String cursor) throws Exception {
		
		final StringBuilder url = new StringBuilder();
		
		url.append("https://mhswsgen2rmsstackapi.appspot.com/cache/downloadproduct");
		url.append("?keyword=");
		url.append(KEYWORD);
		url.append("&client_identifier=");
		url.append(ACCESS_IDENTIFIER);
		url.append("&limit=");
		url.append("256");
		url.append("&context=");
		url.append(context);
		url.append("&qtyonly=true");
		
		if (cursor != null) {
			url.append("&cursor=");
			url.append(cursor);
		}
		
		url.append("&signature=");
		url.append(MessageTokenUtil.computeMD5(SECRET_WORD + KEYWORD));
		
		return Main.fetch(url.toString());
		
	}
	
	private static final void downloadCache(final String context) throws Exception {
		
		String cursor = null;
		
		for (;;) {
			
			final JSONObject result = Main.downloadBatch(context, cursor);
			
			if (!result.getBoolean("success")) {
				System.out.println("Waiting ...");
				Thread.sleep(1024); continue;
			}
						
			final JSONArray batch = result.getJSONArray("batch");
			final int length = batch.length();
			
			if (length == 0) {
				System.out.println("Nothing more; exit!");
				break;
			}
			
			for (int j = 0; j < length; j++) {
				System.out.println(batch.getJSONObject(j).toString(4));
			}
			
			cursor = result.has("cursor") ? result.getString("cursor") : null;
			
		}
						
	}
	
	private static final String buildCache() throws Exception {
				
		final StringBuilder url = new StringBuilder();
		
		url.append("https://mhswsgen2rmsstackapi.appspot.com/cache/build");
		url.append("?keyword=");
		url.append(KEYWORD);
		url.append("&client_identifier=");
		url.append(ACCESS_IDENTIFIER);
		url.append("&signature=");
		url.append(MessageTokenUtil.computeMD5(SECRET_WORD + KEYWORD));
				
		return Main.fetch(url.toString()).getString("context");
						
	}
	
	private static final void downloadQtyOnly(final String context) throws Exception {
		
		String cursor = null;
		
		for (;;) {
			
			final JSONObject result = Main.downloadQtyOnlyBatch(context, cursor);
			
			if (!result.getBoolean("success")) {
				System.out.println("Waiting ...");
				Thread.sleep(1024); continue;
			}
						
			final JSONArray batch = result.getJSONArray("batch");
			final int length = batch.length();
			
			if (length == 0) {
				System.out.println("Nothing more; exit!");
				break;
			}
			
			for (int j = 0; j < length; j++) {
				System.out.println(batch.getJSONObject(j).toString(4));
			}
			
			cursor = result.has("cursor") ? result.getString("cursor") : null;
			
		}
						
	}

	private static final JSONObject fetch(final String url) throws Exception {
		
		System.out.println("FETCHING: " + url);
		
	    final BufferedReader reader = new BufferedReader(new InputStreamReader((new URL(url)).openStream()));
	    
	    final StringBuffer buffer = new StringBuffer(); String line;

	    while ((line = reader.readLine()) != null) {
	    	buffer.append(line);
	    }
	    
	    reader.close();
	    
	    System.out.println("FETCHED: " + (new JSONObject(buffer.toString())).toString(4));
	    
	    return new JSONObject(buffer.toString());
	    		
	}
	
}
