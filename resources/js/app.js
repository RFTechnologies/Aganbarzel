
  import {
    Provider as AppBridgeProvider,
    useAppBridge
  } from "@shopify/app-bridge-react";
  import {createRoot} from 'react-dom/client';
  import React from 'react';
  import { createApp } from "@shopify/app-bridge";
  import { authenticatedFetch } from "@shopify/app-bridge/utilities";
  import { Redirect } from "@shopify/app-bridge/actions/Navigation/Redirect";
  import { AppProvider as PolarisProvider, Tag } from "@shopify/polaris";
  import translations from "@shopify/polaris/locales/en.json";
  import "@shopify/polaris/build/esm/styles.css";

  import {BrowserRouter, Routes, Route} from 'react-router-dom';

  import { Home } from "./pages/home";
  import { Tags } from "./pages/tags";
  import {Rals} from './rals/index';
  import { Navigation } from "./components/Navigation";
import ApolloClient from "apollo-client";
import { HttpLink } from "apollo-link-http";
import { InMemoryCache } from "apollo-cache-inmemory";

  /** Shopify embeds the app with ?host=... (must pass through to App Bridge; never hardcode app domain here). */
  function shopifyHostFromUrl() {
    return new URLSearchParams(window.location.search).get("host") || "";
  }

  const app = createApp({
    apiKey: document.getElementById("apiKey").value,
    host: shopifyHostFromUrl(),
  });
  export const client = new ApolloClient({
    link: new HttpLink({
      credentials: "same-origin",
      fetch: authenticatedFetch(app, yourCustomFetchWrapper), // ensures that all requests triggered by the ApolloClient are authenticated
    }),
    cache: new InMemoryCache(),
  });

  export const yourCustomFetchWrapper = async (uri, options) => {
      const response = await fetch(uri, options);
      if (
        response.headers.get("X-Shopify-API-Request-Failure-Reauthorize") === "1"
      ) {
        const authUrlHeader = response.headers.get(
          "X-Shopify-API-Request-Failure-Reauthorize-Url"
        );

        // const redirect = Redirect.create(app);
        const redirect = Redirect.create(app)
        redirect.dispatch(Redirect.Action.APP, authUrlHeader || `/auth`);
        return null;
      }
      return response;
  };

  export default function App() {

    const config = {
        apiKey : document.getElementById("apiKey").value,
        shopOrigin : document.getElementById("shopOrigin").value,
        host: shopifyHostFromUrl(),
        forceRedirect : true
    };
    return (
      <PolarisProvider i18n={translations}>
        <AppBridgeProvider
          config={config}
        >
            <BrowserRouter>
            <Navigation/>
              <Routes>
                <Route path="/" element={<Home/>}/>
                <Route path="/tags" element={<Tags/>}/>
                <Route path="/rals" element={<Rals/>}/>
                <Route path="*" element={<div>Path not found!</div>}/>
              </Routes>
            </BrowserRouter>
        </AppBridgeProvider>
      </PolarisProvider>
    );
  }
  
  export function userLoggedInFetch(app) {
    const fetchFunction = authenticatedFetch(app);
  
    return async (uri, options) => {
      try {
        const response = await fetchFunction(uri, options);
  
        if (
          response.headers.get("X-Shopify-API-Request-Failure-Reauthorize") === "1"
        ) {
          const authUrlHeader = response.headers.get(
            "X-Shopify-API-Request-Failure-Reauthorize-Url"
          );
    
          const redirect = Redirect.create(app);
          redirect.dispatch(Redirect.Action.APP, authUrlHeader || `/auth`);
          return null;
        }
        return response;
        

      } catch (error) {
        console.error(error)
        throw new Error(error);
      }

    };
  }

  export const fetch = userLoggedInFetch(app);


  const root = createRoot(document.getElementById("app"));
  root.render(
    <App />
  );