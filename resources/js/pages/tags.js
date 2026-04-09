import { 
  Card,
  Listbox,
  Stack,
  BlockStack,
  Icon, 
  Layout,
  Page,
  Button,
  Modal,
  TextField,
  TextContainer,
  Toast,
  Frame
} from "@shopify/polaris";
import {PlusCircleIcon} from '@shopify/polaris-icons';
import React,{useState,useCallback,useEffect} from "react";

import { useAppBridge } from "@shopify/app-bridge-react";
import { fetch } from "../app";


export function Tags(){
    const  [items,setItems] = useState([]);
    const [active, setActive] = useState(false);
    const [activeDM, setActiveDM] = useState(false);
    const [selectedValue, setSelectedValue] = useState(null);
    const [addTag,setAddTag] = useState(null);
    const [slugifyText, setSlugifyText] = useState('');
    const [hasResults, setHasResults] = useState(false);
    const [toastContent, setToastContent] = useState('');

    const app = useAppBridge();

    const toggleModal = useCallback(() => setActive((active) => !active), []);
    const toggleDeleteModal = useCallback(()=>setActiveDM((activeDM)=>!activeDM),[]);

    const deleteTag = async function(){
      const data = await fetch('/tags/delete/'+selectedValue).then(res=>res.json());
      if(data.status == 'success'){
        setItems(data.items);
        setActiveDM(false);
        setToastContent('Tag deleted successfully!');
        setTimeout(()=>{
          setHasResults(true);
        },200);
      }else{
        setToastContent('Failed to delete!');
        setTimeout(()=>{
          setHasResults(true);
        },200);
      }
    }

    const selectItem = function(value){
        setSelectedValue(value);
        if(value == 'addTag'){
            toggleModal();
        }else{
          toggleDeleteModal();
        }
    }

    const onChangeTagText = function(value){
        setAddTag(value);
        setSlugifyText(slugify(value));
    }

    const onAddTag = async function(){
      const formData = new FormData();
      formData.append('name',slugifyText);
      const data = await fetch("/tags/add",{
        method: 'post',
        body:formData
      }).then((res) => {
        setHasResults(true);
        return res.json()
      });
      if(data.status == 'success'){
        setItems(data.items);
        setActive(false);
        setAddTag('');
        setToastContent('Tag Added!')
        setTimeout(()=>{
          setHasResults(true);
        },200);
      }else{
        setActive(false);
        setAddTag('');
        setToastContent(data.message);
        setTimeout(()=>{
          setHasResults(true);
        },200);
      }
    }

    useEffect(()=>{
      async function fetchData(){
        const data = await fetch('/tags').then((res)=>res.json());
        setItems(data.items);
      }
      fetchData();
    },[]);

    function slugify(str)
    {
        str = str.replace(/^\s+|\s+$/g, '');

        // Make the string lowercase
        str = str.toLowerCase();

        // Remove accents, swap ñ for n, etc
        var from = "ÁÄÂÀÃÅČÇĆĎÉĚËÈÊẼĔȆÍÌÎÏŇÑÓÖÒÔÕØŘŔŠŤÚŮÜÙÛÝŸŽáäâàãåčçćďéěëèêẽĕȇíìîïňñóöòôõøðřŕšťúůüùûýÿžþÞĐđßÆa·/_,:;";
        var to   = "AAAAAACCCDEEEEEEEEIIIINNOOOOOORRSTUUUUUYYZaaaaaacccdeeeeeeeeiiiinnooooooorrstuuuuuyyzbBDdBAa------";
        for (var i=0, l=from.length ; i<l ; i++) {
            str = str.replace(new RegExp(from.charAt(i), 'g'), to.charAt(i));
        }

        // Remove invalid chars
        str = str.replace(/[^a-z0-9 -]/g, '') 
        // Collapse whitespace and replace by -
        .replace(/\s+/g, '-') 
        // Collapse dashes
        .replace(/-+/g, '-'); 

        return str;
    }

    const toastMarkup = hasResults && (
      <div style={{'height':'150px'}}>
      <Frame>
        <Toast
          content= {toastContent}
          duration={3000}
          onDismiss={() => setHasResults(false)}
        />
      </Frame>
      </div>
    );

    return(
      <>
        <Page fullWidth>
        <Layout>
          <Layout.Section secondary>
        <Card>
            <Listbox accessibilityLabel="Listbox with Action example" onSelect={selectItem}>
                {items.map(item=>(
                  <Listbox.Option value={item.id} key={item.id} divider>{item.name}</Listbox.Option>
                ))}
                <Listbox.Action value="addTag">
                  <BlockStack gap={2} style={{flexDirection:'row'}}>
                    <Icon source={PlusCircleIcon} color="base" />
                    <div>Add item</div>
                  </BlockStack>
                </Listbox.Action>
            </Listbox>
        </Card>
        </Layout.Section>
        </Layout>
        <Modal
          open={active}
          onClose={toggleModal}
          title="Add a Tag"
          primaryAction={{
            content: 'Close',
            onAction: toggleModal,
          }}
        >
          <Modal.Section>
            <BlockStack>
              <div>
                <TextContainer>
                <p>
                Please add your tag in slug format i.e: rf-product, new-tag etc
                </p>
                </TextContainer>
              </div>
              <div fill>
                <TextField
                  label="Tag Name"
                  value={addTag}
                  placeholder="Enter tag..."
                  onChange={onChangeTagText}
                  autoComplete="off"
                  connectedRight={
                    <Button primary onClick={onAddTag}>
                      Add
                    </Button>
                  }
                />
              </div>
            </BlockStack>
          </Modal.Section>
        </Modal>

        <Modal
          open={activeDM}
          onClose={toggleDeleteModal}
          title="Delete a Tag"
          primaryAction={{
            content: 'Close',
            onAction: toggleDeleteModal,
          }}
          secondaryActions={[
            {
              content: 'Delete',
              onAction: deleteTag
            }
          ]}
        >
        <Modal.Section>
            <BlockStack>
              <div>
                <TextContainer>
                <p>
                Remove the tag from the list
                </p>
                </TextContainer>
              </div>
            </BlockStack>
          </Modal.Section>
        </Modal>
        {toastMarkup}
        </Page>
      </>
    );
}