# BioCLIP 熱載 YOLO 候選類別設計

**目標：** BioCLIP 分類時，以目前實際熱載的兩至三組 YOLO 偵測模型之類別聯集作為候選，並在低信心結果中告知使用者現行可辨識範圍。

## 範圍

- 新增 BioCLIP 的 `candidate_source=loaded_yolo` 選項。
- 保留既有明確 `candidate_labels` 請求的行為。
- 不讀取歷史訓練紀錄，也不因為未熱載的模型而宣稱可辨識。
- 僅在 `candidate_source=loaded_yolo` 的未知結果啟用 Gemma4 補充辨識。

## 類別來源與結果

1. Hub 從 `yolo_model_deployments` 取得 `actual_state='hot'` 的 CPU 與 GPU 部署，並連結對應 `yolo_model_versions.labels_json`。
2. 依穩定部署順序合併、去除空值與重複項，得到 `supported_labels`；這是目前 YOLO 可辨識範圍。
3. BioCLIP 候選字典為 `supported_labels` 加上固定防呆類別：`人類`、`貓`、`狗`、`汽車`、`機車`、`室內／建築`、`空景／無目標`。防呆類別不會列入 `supported_labels`。
4. 沒有任何熱載 YOLO 類別時，`candidate_source=loaded_yolo` 明確回傳 `no_active_yolo_models`；不得回退到歷史模型或預設候選字典。
5. 合併後的候選類別若超過 BioCLIP 的上限，明確回傳 `candidate_limit_exceeded`；不得靜默截斷。

## 分類狀態

- 最高候選分數大於等於 `0.40`，且屬於 `supported_labels`：`recognized`。
- 最高候選分數大於等於 `0.40`，且屬於防呆類別：`out_of_scope`，例如「非目標：人類」。
- 最高候選分數低於 `0.40`：`unknown`，訊息必須包含 `supported_labels`，例如「無法辨識；目前模型可辨識：甲、乙、丙」。

當 `candidate_source=loaded_yolo` 為 `unknown` 時，Gateway 將原始上傳圖暫存於 Gemma4 可讀取的私有工作路徑，內部請求 Gemma4 Photo Vision，並在請求結束的 `finally` 清除暫存檔。不建立 `photo_assets` 資料列、不產生 `image_id`，也不透過公開 `photo_upload`／`photo` API 取得或暴露檔案。

Gemma4 成功時，回應新增 `gemma_fallback`，其 `status` 為 `available`、`answer` 為 Gemma4 的文字辨識結果。頂層狀態仍是 `unknown`，前端顯示為「Gemma4 補充辨識（參考）」，不得當成 YOLO 偵測標籤。Gemma4 未就緒、逾時或回應無效時，保留原本 `unknown`、訊息與 `supported_labels`，並回傳不含內部細節的 `gemma_fallback.status=unavailable`。

回應保留原本的 `labels` 排名；新增的狀態、訊息、`supported_labels` 與 `gemma_fallback` 只用於讓呼叫端正確呈現結果。

## 相容性與驗證

- 未使用 `candidate_source=loaded_yolo` 的既有 BioCLIP 請求完全維持原行為，不會呼叫 Gemma4。
- 測試涵蓋：多個熱載模型的聯集去重、未熱載模型不入列、防呆類別不進 `supported_labels`、40% 未知門檻、無熱載模型、候選上限錯誤、Gemma4 的私有暫存清除、成功補充結果與失敗時保留未知結果。
