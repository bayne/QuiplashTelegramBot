import {htmlToElement} from "./util.js";

export default class Prompt extends HTMLElement {

    constructor() {
        super();
        const shadow = this.attachShadow({mode: "open"});
        const question = this.getAttribute("question");

        shadow.append(htmlToElement(`
        <style>
        div#form {
            height: 100%;
            width: 100%;
            margin: 0;
            justify-content: space-evenly;
            display: flex;
            flex-direction: column;
        }
        label {
            font-size: 2em;
            display: block;
            margin: 1.3em auto 1.3em auto;
            text-align: center;
        }
        input[type="text"] {
            display: block;
            font-size: 2em;
            width: 90%;
            border: none;
            margin-left: auto;
            margin-right: auto;
            border-bottom: 2px solid #ea526f;
            background: transparent;
            color: #ea526f;
        }
        button {
            border: none;
            display: block;
            margin-left: auto;
            margin-right: auto;
            background-color: #279Af1;
            letter-spacing: 3px;
            color: #c4e3fb;
            border-bottom-left-radius: 3px;
            border-bottom-right-radius: 3px;
            box-shadow: 0 4px 8px 0 rgba(0,0,0,.2), 0 6px 20px 0 rgba(0,0,0,.19);
            padding: 0.5em;
            font-size: 2em;
        }
        button.disabled {
            background-color: #ebf5fd;
        }
        </style>
        `))

        shadow.append(htmlToElement(`<div id="form">
            <label>${question}
                <input id="answer" type="text"/>
            </label>
            <button id="submit">Submit</button>
        </div>`));

        this.handleSubmit = this.handleSubmit.bind(this);
    }

    connectedCallback() {
        const submitButton = this.shadowRoot.querySelector("#submit");

        submitButton.addEventListener("click", this.handleSubmit);
    }

    async handleSubmit() {
        const group_id = this.getAttribute("group");
        const token = this.getAttribute("token");
        const answer = this.shadowRoot.querySelector("#answer").value;
        const params = new URLSearchParams({group_id, token});
        const submitButton = this.shadowRoot.querySelector("#submit")

        submitButton.setAttribute("disabled", "disabled");
        submitButton.setAttribute("class", "disabled");

        await fetch(`/app?${params.toString()}`, {
            method: 'POST',
            body: JSON.stringify({ answer })
        });
        this.dispatchEvent(new CustomEvent(
            "next_question", {
                bubbles: true,
                composed: true
            }
        ));
    }
}